<?php

namespace FpDbTest;

use Exception;
use InvalidArgumentException;
use mysqli;

class Database implements DatabaseInterface
{

    private mysqli $mysqli;

    /**
     * Значения для спецификаторов
     * @var array
     */
    private array $args = [];

    /**
     * Статус текущего блока (если он пропускается, значения соответствующие ему пропускаем)
     * @var bool
     */
    private bool $skipping_block = false;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buildQuery(string $query, array $args = []): string
    {
        $this->args = $args;
        return $this->parseSql($query);
    }

    public function skip(): string
    {
        return '$$!@@!$$';
    }

    /**
     * Парсер sql
     * @param string $sql
     * @return string
     */
    private function parseSql(string $sql): string
    {
        //регулярка ищет либо спецификатор, либо блок целиком,
        // функция замены значения ($this->replaceValue()) сама вызовет этот же парсер для внутренностей блока
        //регулярка составлена без возможности использования вложенных блоков,
        // но, если что, можно добавить |(?R) и теоретически все будет работать так же круто и с вложенными блоками
        return preg_replace_callback('~(?>(?<spec>\?(?<spec_symbol>[dfa#]?)))
            |(?>(?<block>\{(?<block_inner>[^{}]*)\}))~ismx', [$this, 'replaceValue'], $sql);
    }

    /**
     * Замена блока разобранного sql на значение из передаваемых аргументов
     * @param array $matches
     * @return string
     * @throws InvalidArgumentException
     * @throws Exception
     */
    private function replaceValue(array $matches): string
    {
        if (!empty($matches['spec'])) {
            if (empty($this->args)) {
                throw new InvalidArgumentException('Not enough values');
            }
            $value = array_shift($this->args);
            if ($value === $this->skip()) {
                $this->skipping_block = true;
                return '';
            }
            if ($this->skipping_block) {
                return '';
            }
            //не вижу смысла загонять спецификаторы в константы\енумы, т.к. в тестах используются строки, да и так использовать реально удобнее.
            return match ($matches['spec_symbol']) {
                '' => $this->convertScalar($value),
                'd' => $this->convertInt($value),
                'f' => $this->convertFloat($value),
                'a' => $this->convertArray($value),
                '#' => $this->convertIdentifier($value),
                default => throw new Exception('Unknown specifier'),
            };
        } elseif (!empty($matches['block'])) {
            $replacement = $this->parseSql($matches['block_inner']);
            if ($this->skipping_block) {
                $replacement = '';
            }
            $this->skipping_block = false;//сбрасываем флаг для следующих блоков
            return $replacement;
        } else {
            throw new Exception('unknown blocks');
        }
    }

    /**
     * ?d - конвертация в целое число
     * Параметры ?, ?d, ?f могут принимать значения null (в этом случае в шаблон вставляется NULL).
     * @param mixed $value
     * @return int|string
     * @throws InvalidArgumentException
     */
    private function convertInt(mixed $value): string|int
    {
        return match (true) {
            is_null($value) => 'NULL',
            !is_scalar($value) => throw new InvalidArgumentException('Value not scalar'),
            default => intval($value)
        };
    }

    /**
     * ?f - конвертация в число с плавающей точкой
     * Параметры ?, ?d, ?f могут принимать значения null (в этом случае в шаблон вставляется NULL).
     * @param mixed $value
     * @return string|float
     * @throws InvalidArgumentException
     */
    private function convertFloat(mixed $value): string|float
    {
        return match (true) {
            is_null($value) => 'NULL',
            !is_scalar($value) => throw new InvalidArgumentException('Value not scalar'),
            default => floatval($value)
        };
    }

    /**
     * ?# - идентификатор или массив идентификаторов
     * @param string|array $value
     * @return string
     * @throws InvalidArgumentException
     */
    private function convertIdentifier(string|array $value): string
    {
        return implode(', ', array_map(function($identifier) {
            return match (true) {
                !is_scalar($identifier) => throw new InvalidArgumentException('Value not scalar'),
                // разрешенные символы отличаются от системы
                // для схем и таблиц - «Любой символ, допустимый в имени каталога (файла для таблиц), за исключением `/' или `.'» => для Windows и Linux есть различия
                // для столбцов и псевдонимов вообще все символы кроме «ASCII(0), ASCII(255) или кавычки»
                // я за использование здравого смысла и имена столбцов типа `$)@#$^*!:` буду считать неприемлемым
                // можно заморочиться и делить идентификатор по точке и три регулярки для каждой составляющей проверять
                !preg_match('~^[0-9a-z\-_]+$~i', $identifier) => throw new InvalidArgumentException('Value incorrect'),
                //будем считать что передают идентификаторы без обратных кавычек. А массив идентификаторов - это не набор [схема, таблица, столбец] для одного столбца, а список столбцов
                default => '`' . str_replace('.', '`.`', $identifier) . '`'
            };
        }, (array) $value));
    }

    /**
     * Если спецификатор не указан, то используется тип переданного значения, но допускаются только типы string, int, float, bool (приводится к 0 или 1) и null
     * Параметры ?, ?d, ?f могут принимать значения null (в этом случае в шаблон вставляется NULL).
     * @param scalar|null $value
     * @return string|int|float
     */
    private function convertScalar(string|int|float|bool|null $value): string|int|float
    {
        //по типу переменной мне не очень нравится,
        //я бы добавила еще условия на проверку в строке $value инта или флоата, мало ли строка прилетит, пусть лучше не sql конвертирует, а php
        return match (true) {
            is_null($value) => 'NULL',
            is_int($value) || is_bool($value) => $this->convertInt($value),
            is_float($value) => $this->convertFloat($value),
            default => '\'' . $this->mysqli->real_escape_string($value) . '\''
        };
    }

    /**
     * ?a - массив значений
     * Массив (параметр ?a) преобразуется либо в список значений через запятую (список), либо в пары идентификатор и значение через запятую (ассоциативный массив).
     * Каждое значение из массива форматируется в зависимости от его типа (идентично универсальному параметру без спецификатора).
     * @param array $value
     * @return string
     */
    private function convertArray(array $value): string
    {
        //из тз не очень понятно, какие проверки на значения хочется, поэтому вместо is_array внутри функции, объявлен тип аргумента функции
        if (array_is_list($value)) {
            return implode(', ', array_map(function ($val) {
                return $this->convertScalar($val);
            }, $value));
        } else {
            return implode(', ', array_map(function ($key, $val) {
                return $this->convertIdentifier($key) . ' = ' . $this->convertScalar($val);
            }, array_keys($value), $value));
        }
    }

}
