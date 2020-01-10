<?php

/**
 * MsgPack serializer/unserializer (only php)
 *
 * @category MsgPack
 * @package  PurePhpMsgPack
 * @author   Nobuo Tsuchiya <develop@m.tsuchi99.net>
 * @version  Release: @package_version@
 * @link     https://github.com/Tsuchiy/PurePhpMsgPack
 * @since    Class available since Release 1.0.0
 */
class PurePhpMsgPack
{

    /**
     * serialize
     *
     * @param mixed $value
     * @return string
     */
    public static function serialize($value): string
    {
        if (is_object($value)) {
            if (method_exists($value, 'serialize')) {
                return $value->serialize();
            } elseif (method_exists($value, '__wakeup')) {
                $value->__wakeup();
            }
        }

        $type = gettype($value);
        switch ($type) {
            case "NULL":
                return self::getSerializedNull();
            case "boolean":
                return self::booleanSerializer($value);
            case "integer":
                return self::integerSerializer($value);
            case "double":
                return self::doubleSerializer($value);
            case "string":
                // phpのstringをバイナリか判定するのは利点がないのでなのでとりあえず全部stringで実装
                return self::stringSerializer($value);
            case "array":
                return self::arraySerializer($value);
            case "object":
                return self::objectSerializer($value);
            case "resource":
            default:
                trigger_error(
                    '[PurePhpMsgPack] (MsgPack::serialize) type is unsupported, encoded as null',
                    E_USER_WARNING
                );
                return self::getSerializedNull();
        }
    }

    /**
     * null用serializer
     *
     * @param null $value
     * @return char
     */
    private static function getSerializedNull(): string
    {
        return chr(0xc0);
    }

    /**
     * boolean用serializer
     *
     * @param boolean $value
     * @return char
     */
    private static function booleanSerializer(bool $value): string
    {
        return $value ? chr(0xc3) : chr(0xc2);
    }

    /**
     * integer用serializer
     *
     * @param integer $value
     * @return string
     */
    private static function integerSerializer(int $value): string
    {
        if (($value >= - (1 << 5)) && ($value < (1 << 7))) {
            return pack("c", $value);
        } elseif (($value < 0) && ($value >= - (1 << 7))) {
            return chr(0xd0) . pack("c", $value);
        } elseif (($value < 0) && ($value >= - (1 << 15))) {
            $bin = pack("n", (1 << 16) + $value);
            return chr(0xd1) . chr(ord($bin) | 0x80) . substr($bin, 1);
        } elseif (($value < 0) && ($value >= - (1 << 31))) {
            $bin = pack("N", (1 << 32) + $value);
            return chr(0xd2) . chr(ord($bin) | 0x80) . substr($bin, 1);
        } elseif (($value > 0) && ($value < (1 << 8))) {
            return chr(0xcc) . pack("C", $value);
        } elseif (($value > 0) && ($value < (1 << 16))) {
            return chr(0xcd) . pack("n", $value);
        } elseif (($value > 0) && ($value < (1 << 32))) {
            return chr(0xce) . pack("N", $value);
        } elseif (PHP_INT_SIZE == 4) {
            trigger_error(
                '[PurePhpMsgPack] (MsgPack::serialize) too large integer, encoded as null',
                E_USER_WARNING
            );
            return self::getSerializedNull();
        } else {
            $is_negative = false;
            $rtn = chr(0xcf);
            if ($value < 0) {
                $is_negative = true;
                $rtn = chr(0xd3);
                $value += (1 << 62);
                $value += (1 << 62);
            }
            $upper = $value >> 32;
            $lower = $value % (1 << 32);
            $bin = pack("N", $upper) . pack("N", $lower);
            if ($is_negative) {
                $bin = chr(ord($bin) | 0x80) . substr($bin, 1);
            }
            return $rtn . $bin;
        }
    }

    /**
     * double用serializer
     *
     * @param double $value
     * @return string
     */
    private static function doubleSerializer(float $value): string
    {
        // おそらくPHPは単精度は使わないはず
        return chr(0xcb) . strrev(pack("d", $value));
    }

    /**
     * string用serializer
     * @param string $value
     * @return string
     */
    private static function stringSerializer(string $value): string
    {
        $len = strlen($value);
        // 本家c++は0xd9に未対応らしい
        // peclのmsgpackにはmsgpack.use_str8_serializationが追加されてる
        if ($len < 32) {
            $bin = '101' . str_pad(decbin($len), 5, "0", STR_PAD_LEFT);
            return chr(bindec($bin)) . $value;
        } elseif ($len < (1 << 8)) {
            return chr(0xd9) . chr($len) . $value;
        } elseif ($len < (1 << 16)) {
            $bin = pack("i", $len);
            return chr(0xda) . $bin[1] . $bin[0] . $value;
        } elseif ($len < (1 << 32)) {
            $bin = pack("i", $len);
            return chr(0xdb) . strrev($bin) . $value;
        } else {
            trigger_error(
                '[PurePhpMsgPack] (MsgPack::serialize) too long string, encoded as empty',
                E_USER_WARNING
            );
            return chr(bindec('10100000'));
        }
    }

    /**
     * map用header作成
     * @param int $cnt // count of items
     * @return string
     */
    private static function makeMapHeader(int $cnt): ?string
    {
        if ($cnt < 15) {
            return chr(0x80 | $cnt);
        } elseif ($cnt < (1 << 16)) {
            return chr(0xde) . pack("n", $cnt);
        } elseif ($cnt < (1 << 32)) {
            return chr(0xdf) . pack("N", $cnt);
        }
        trigger_error('[PurePhpMsgPack] (MsgPack::serialize) too many items to array', E_USER_ERROR);
        return null;
    }

    /**
     * array用serializer
     * @param array $value
     * @return string
     */
    private static function arraySerializer(array $value): string
    {
        // phpのarrayは基本ハッシュ
        $rtn = '';
        $cnt = count($value);
        $rtn .= self::makeMapHeader($cnt);
        foreach ($value as $key => $object) {
            $rtn .= self::serialize($key);
            $rtn .= self::serialize($object);
        }
        return $rtn;
    }

    /**
     * object用serializer
     * @param object $value
     * @return string
     */
    private static function objectSerializer(object $value): string
    {
        $rtn = '';
        $refObj = new ReflectionObject($value);
        $properties = $refObj->getProperties();
        $rtn .= chr(0xc0);
        $className = $refObj->getName();
        $rtn .= self::stringSerializer($className);
        $itemCnt = 1;
        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }
            if ($property->isPrivate()) {
                $rtn .= self::stringSerializer(chr(0) . $className . chr(0) . $property->getName());
            } elseif ($property->isProtected()) {
                $rtn .= self::stringSerializer(chr(0) . chr(0x2a) . chr(0) . $property->getName());
            } elseif ($property->isPublic()) {
                $rtn .= self::stringSerializer($property->getName());
            }
            $property->setAccessible(true);
            $rtn .= self::serialize($property->getValue($value));
            ++$itemCnt;
        }
        return self::makeMapHeader($itemCnt) . $rtn;
    }

    /**
     * unserialize
     * @param string $binary
     * @return mixed
     */
    public static function unserialize(string $binary)
    {
        $value = self::variableUnserializer($binary);
        if (strlen($binary)) {
            trigger_error('[PurePhpMsgPack] (MsgPack::unserialize) Extra bytes exist', E_USER_WARNING);
        }

        if (is_object($value)) {
            if (method_exists($value, 'unserialize')) {
                $value->unserialize($binary);
            } elseif (method_exists($value, '__wakeup')) {
                $value->__wakeup();
            }
        }
        return $value;
    }

    /**
     * Unserializeの実態
     *
     * @param string $str
     * @return mixed
     */
    private static function variableUnserializer(string &$str)
    {
        $ord = ord($str);
        switch ($ord) {
            case 0xc0: // null
                $str = substr($str, 1);
                return null;
            case 0xc2: // boolean false
                $str = substr($str, 1);
                return false;
            case 0xc3: // boolean true
                $str = substr($str, 1);
                return true;
            case 0xcb: // double(float64)
                $rtn = unpack("d", strrev(substr($str, 1, 8)));
                $str = substr($str, 9);
                return array_shift($rtn);
            case 0xcc: // uint8
                $rtn = self::uintUnpack(substr($str, 1, 1));
                $str = substr($str, 2);
                return $rtn;
            case 0xcd: // uint16
                $rtn = self::uintUnpack(substr($str, 1, 2));
                $str = substr($str, 3);
                return $rtn;
            case 0xce: // uint32
                $rtn = self::uintUnpack(substr($str, 1, 4));
                $str = substr($str, 5);
                return $rtn;
            case 0xcf: // uint64
                $rtn = self::uintUnpack(substr($str, 1, 8));
                $str = substr($str, 9);
                return $rtn;
            case 0xd0: // int8
                $rtn = self::intUnpack(substr($str, 1, 1));
                $str = substr($str, 2);
                return $rtn;
            case 0xd1: // int16
                $rtn = self::intUnpack(substr($str, 1, 2));
                $str = substr($str, 3);
                return $rtn;
            case 0xd2: // int32
                $rtn = self::intUnpack(substr($str, 1, 4));
                $str = substr($str, 5);
                return $rtn;
            case 0xd3: // int64
                $rtn = self::intUnpack(substr($str, 1, 8));
                $str = substr($str, 9);
                return $rtn;
            case 0xd9: // string8
                $len = ord(substr($str, 1, 1));
                $rtn = substr($str, 2, $len);
                $str = substr($str, $len + 2);
                return $rtn;
            case 0xda: // string16
                $len = self::uintUnpack(substr($str, 1, 2));
                $rtn = substr($str, 3, $len);
                $str = substr($str, $len + 3);
                return $rtn;
            case 0xdb: // string32
                $len = self::uintUnpack(substr($str, 1, 4));
                $rtn = substr($str, 5, $len);
                $str = substr($str, $len + 5);
                return $rtn;
            case 0xde: // map16,object16
                $cnt = self::intUnpack(substr($str, 1, 2));
                $str = substr($str, 3);
                return self::mapUnserializer($str, $cnt);
            case 0xdf: // map32,object32
                $cnt = self::intUnpack(substr($str, 1, 4));
                $str = substr($str, 5);
                return self::mapUnserializer($str, $cnt);
            default:
                if ($ord >= 0xa0 && $ord <= 0xbf) { // fixstring
                    $len = ($ord & 0x1f);
                    $rtn = substr($str, 1, $len);
                    $str = substr($str, $len + 1);
                    return $rtn;
                }
                if ($ord >= 0x80 && $ord <= 0x8f) { // fixmap
                    $cnt = $ord & 0x0f;
                    $str = substr($str, 1);
                    return self::mapUnserializer($str, $cnt);
                }
                if ($ord >= 0x00 && $ord <= 0x7f) { // positive int
                    $str = substr($str, 1);
                    return (int) $ord;
                }
                if ($ord >= 0xe0 && $ord <= 0xff) { // negative int
                    $rtn = self::uintUnpack(substr($str, 0, 1));
                    $str = substr($str, 1);
                    return (int) $rtn;
                }
                trigger_error(
                    '[PurePhpMsgPack] (MsgPack::unserialize) type is unsupported, encoded as null',
                    E_USER_ERROR
                );
                return null;
        }
    }

    /**
     * uint unserializer
     *
     * @param string $str
     * @return integer
     */
    private static function uintUnpack(string $str): int
    {
        $rtn = 0;
        while ($chr = substr($str, 0, 1)) {
            $rtn = ($rtn << 8) + ord($chr);
            $str = substr($str, 1);
        }
        return (int) $rtn;
    }

    /**
     * int unserializer
     *
     * @param string $str
     * @return integer
     */
    private static function intUnpack(string $str): int
    {
        $top = substr($str, 0, 1);
        if (ord($top) < 0x80) {
            return self::uintUnpack($str);
        } else {
            return (int) ((int) (1 << ((8 * strlen($str)) - 1))
                - self::uintUnpack(chr(ord($str) & 0x7f) . substr($str, 1)));
        }
    }

    /**
     * array/object unserializer
     *
     * @param string $str
     * @param integer $item_count
     * @return array|object
     */
    private static function mapUnserializer(string &$str, int $item_count)
    {
        $array_keys = array();
        $array_values = array();
        $null_key_value = null;
        for ($i = 0; $i < $item_count; ++$i) {
            $key = self::variableUnserializer($str);
            $value = self::variableUnserializer($str);
            if (is_null($key)) {
                $null_key_value = $value; // 多分class name
            } else {
                $array_keys[] = $key;
                $array_values[] = $value;
            }
        }
        if (!is_null($null_key_value)) {
            if (is_string($null_key_value) && class_exists($null_key_value)) {
                return self::objectUnserializer($null_key_value, $array_keys, $array_values);
            }
            $array_keys[] = "";
            $array_values[] = $null_key_value;
        }
        return array_combine($array_keys, $array_values);
    }

    /**
     * object unserializer
     *
     * @param string $class_name
     * @param array $array_keys
     * @param array $array_values
     * @return object
     */
    private static function objectUnserializer(string $class_name, array $array_keys, array $array_values): object
    {
        @$obj = new $class_name;
        $refObj = new ReflectionObject($obj);
        while (!is_null($key = array_shift($array_keys))) {
            $value = array_shift($array_values);
            if (ord($key) === 0) {
                // private or protected
                if (substr($key, 0, 3) === chr(0) . chr(0x2a) . chr(0)) {
                    // is Protected
                    $property_name = substr($key, 3);
                } else {
                    // is Private
                    $header_str = chr(0) . $class_name . chr(0);
                    if (substr($key, 0, strlen($header_str)) !== ($header_str)) {
                        trigger_error(
                            '[PurePhpMsgPack] (MsgPack::unserialize) object has wrong property name',
                            E_USER_ERROR
                        );
                        continue;
                    }
                    $property_name = substr($key, strlen($header_str));
                }
            } else {
                // is Public
                $property_name = $key;
            }
            $property = $refObj->getProperty($property_name);
            $property->setAccessible(true);
            $property->setValue($obj, $value);
        }
        return $obj;
    }
}
