<?php
/**
 * Created by PhpStorm.
 * User: Chris
 * Date: 5-12-13
 * Time: 17:20
 */

namespace Devristo\Torrent;

class Bee {

    private function encode_string($string){
        return sprintf("%d:%s", strlen($string), $string);
    }

    private function encode_int($int){
        return 'i'.$int.'e';
    }

    private function encode_list(array $list){
        $encoded = 'l';

        foreach($list as $item)
            $encoded .= $this->encode($item);

        $encoded .= 'e';

        return $encoded;
    }

    private function encode_dict(array $dict){
        $encoded = 'd';

        ksort($dict, SORT_STRING);

        foreach($dict as $key => $value){
            $encoded .= $this->encode_string($key);
            $encoded .= $this->encode($value);
        }

        $encoded .= 'e';
        return $encoded;
    }

    private function is_list(array $arr){
        for (reset($arr); is_int(key($arr)); next($arr));
        return is_null(key($arr));
    }

    private function is_dict(array $arr){
        for (reset($arr); is_int(key($arr)) || is_string(key($arr)); next($arr));
        return is_null(key($arr));
    }

    /**
     * @param $object
     * @return string
     */
    public function encode($object){
        if(is_int($object) || ctype_digit($object))
            return $this->encode_int($object);
        elseif(is_string($object))
            return $this->encode_string($object);
        elseif(is_array($object))
            if($this->is_list($object))
                return $this->encode_list($object);
            elseif($this->is_dict($object))
                return $this->encode_dict($object);
            else throw new \InvalidArgumentException("Input is not valid");
        else throw new \InvalidArgumentException("Input is not valid");
    }


    public function eatInt(&$string, &$pos){
        // Eat the i
        $pos++;

        $i = $pos;
        while($i < strlen($string)){
            if(ctype_digit($string[$i]) || $string[$i] == '-')
                $i++;
            elseif($string[$i] == 'e'){
                $result = substr($string, $pos, $i-$pos);
                $pos = $i+1;
                return $result;
            }else
                break;
        }

        throw new \InvalidArgumentException("Invalid int format");
    }

    public function eatList(&$string, &$pos){
        // Eat the l
        $pos++;

        $i = $pos;
        $items = array();
        while($i < strlen($string)){

            if($string[$i] == 'e'){
                $pos = $i+1;
                return $items;
            }else {
                $items[] = $this->decode($string, $i);
            }
        }

        throw new \InvalidArgumentException("Invalid list format");
    }

    public function eatDict(&$string, &$pos){
        // Eat the d
        $pos++;

        $i = $pos;
        $items = array();
        while($i < strlen($string)){

            if($string[$i] == 'e'){
                $pos = $i+1;
                return $items;
            }else {
                $key = $this->decode($string, $i);
                $value = $this->decode($string, $i);

                $items[$key] = $value;
            }
        }

        throw new \InvalidArgumentException("Invalid dict format");
    }

    public function eatString(&$string, &$pos){
        $i = $pos;
        while($i < strlen($string)){
            if(ctype_digit($string[$i]))
                $i++;
            elseif($string[$i] == ':'){
                $length = (int)substr($string, $pos, $i-$pos);

                if($length + $i < strlen($string)){
                    $result = substr($string, $i+1, $length);
                    $pos = $length + $i + 1;
                    return $result;
                }else break;

            }else
                break;
        }
        throw new \InvalidArgumentException("Invalid string format");
    }

    /**
     * @param $string
     * @param int $pos
     * @return mixed
     */
    public function decode($string, &$pos=0){
        while($pos < strlen($string)){
            switch($string[$pos]){
                case 'i':
                    return $this->eatInt($string, $pos);
                case 'l':
                    return $this->eatList($string, $pos);
                case 'd':
                    return $this->eatDict($string, $pos);
                default:
                    if(ctype_digit($string[$pos]))
                        return $this->eatString($string, $pos);
                    else throw new \InvalidArgumentException("Invalid input format");

            }
        }
    }
} 