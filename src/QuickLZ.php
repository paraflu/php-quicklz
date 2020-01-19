<?php
// QuickLZ data compression library
//
// QuickLZ can be used for free under the GPL 1, 2 or 3 license (where anything
// released into public must be open source) or under a commercial license if such
// has been acquired (see http://www.quicklz.com/order.html). The commercial license
// does not cover derived or ported versions created by third parties under GPL.
//
// Only a subset of the C library has been ported, namely level 1 and 3 not in
// streaming mode.
//
// Version: 1.0
// Author: Andrea Forlin

namespace VergeIT;

class QuickLZ
{
    // Streaming mode not supported
    const QLZ_STREAMING_BUFFER = 0;

    // Bounds checking not supported. Use try...catch instead
    const QLZ_MEMORY_SAFE = 0;

    const QLZ_VERSION_MAJOR = 1;
    const QLZ_VERSION_MINOR = 5;
    const QLZ_VERSION_REVISION = 0;

    // Decrease static::QLZ_POINTERS_3 to increase compression speed of level 3. Do not
    // edit any other constants!
    private const HASH_VALUES = 4096;
    private const MINOFFSET = 2;
    const UNCONDITIONAL_MATCHLEN = 6;
    const UNCOMPRESSED_END = 4;
    private const CWORD_LEN = 4;
    private const DEFAULT_HEADERLEN = 9;
    private const QLZ_POINTERS_1 = 1;
    private const QLZ_POINTERS_3 = 16;

    static private function headerLen($source)
    {
        return (($source[0] & 2) == 2) ? 9 : 3;
    }

    static public function sizeDecompressed($source)
    {
        if (static::headerLen($source) == 9)
            return (int)static::fast_read($source, 5, 4);
        else
            return (int)static::fast_read($source, 2, 1);
    }

    /**
     * Mime System.arraycopy
     */
    static private function arraycopy($src, $srcPos, &$dest, $destPos, $length)
    {
        $a = array_slice($src, $srcPos, $length);
        $b = array_slice($dest, 0, $destPos);
        $dest = array_merge($b, $a, array_slice($dest, $destPos + $length));
    }

    static public function sizeCompressed($source)
    {
        if (static::headerLen($source) == 9)
            return (int)static::fast_read($source, 1, 4);
        else
            return (int)static::fast_read($source, 1, 1);
    }

    private static function write_header(&$dst, int $level, bool $compressible, int $size_compressed, int $size_decompressed)
    {
        $dst[0] = (2 | ($compressible ? 1 : 0));
        $dst[0] |= ($level << 2);
        $dst[0] |= (1 << 6);
        $dst[0] |= (0 << 4);
        static::fast_write($dst, 1, $size_decompressed, 4);
        static::fast_write($dst, 5, $size_compressed, 4);
    }

    private static function declare2DArray($m, $n, $value = 0)
    {
        return array_fill(0, $m, array_fill(0, $n, $value));
    }

    /**
     * @param int[] $source
     * @param int $level
     * @return array
     * @throws \Exception
     */
    public static function compress($source, int $level)
    {
        $src = 0;
        $source = array_values($source); // reindex to 0 start
        $dst = static::DEFAULT_HEADERLEN + static::CWORD_LEN;
        $cword_val = 0x80000000;
        $cword_ptr = static::DEFAULT_HEADERLEN;
        $destination = array_fill(0, count($source) + 400, 0);
        $cachetable = array_fill(0, static::HASH_VALUES, 0);
        $hash_counter = array_fill(0, static::HASH_VALUES, 0);
        $fetch = 0;
        $last_matchstart = (count($source) - static::UNCONDITIONAL_MATCHLEN - static::UNCOMPRESSED_END - 1);
        $lits = 0;

        if ($level != 1 && $level != 3)
            throw new \Exception("Php version only supports level 1 and 3");

        if ($level == 1)
            $hashtable = static::declare2DArray(static::HASH_VALUES, static::QLZ_POINTERS_1);
        else
            $hashtable = static::declare2DArray(static::HASH_VALUES, static::QLZ_POINTERS_3);

        if (count($source) == 0)
            return [0];

        if ($src <= $last_matchstart)
            $fetch = (int)static::fast_read($source, $src, 3);

        while ($src <= $last_matchstart) {
            if (($cword_val & 1) == 1) {
                if (($src > 3 * (count($source) >> 2) && $dst > $src - ($src >> 5))) {
                    $d2 = array_fill(0, count($source) +static::DEFAULT_HEADERLEN, 0);
                    static::write_header($d2, $level, false, count($source), count($source) + static::DEFAULT_HEADERLEN);
                    static::arraycopy($source, 0, $d2, static::DEFAULT_HEADERLEN, count($source));
                    return $d2;
                }

                static::fast_write($destination, $cword_ptr, (static::logical_right_shift($cword_val, 1) | 0x80000000), 4);
                $cword_ptr = $dst;
                $dst += static::CWORD_LEN;
                $cword_val = 0x80000000;
            }

            if ($level == 1) {
                $hash = ((static::logical_right_shift($fetch, 12) ^ $fetch) & (static::HASH_VALUES - 1));
                $o = $hashtable[$hash][0];
                $cache = $cachetable[$hash] ^ $fetch;

                $cachetable[$hash] = $fetch;
                $hashtable[$hash][0] = $src;

                if ($cache == 0 && $hash_counter[$hash] != 0 && ($src - $o > static::MINOFFSET || ($src == $o + 1 && $lits >= 3 && $src > 3 && $source[$src] == $source[$src - 3] && $source[$src] == $source[$src - 2] && $source[$src] == $source[$src - 1] &&
                            $source[$src] == $source[$src + 1] && $source[$src] == $source[$src + 2]))) {
                    $cword_val = (static::logical_right_shift($cword_val, 1) | 0x80000000);
                    if ($source[$o + 3] != $source[$src + 3]) {
                        $f = 3 - 2 | ($hash << 4);
                        $destination[$dst + 0] = static::logical_right_shift($f, 0 * 8);
                        $destination[$dst + 1] = static::logical_right_shift($f, 1 * 8);
                        $src += 3;
                        $dst += 2;
                    } else {
                        $old_src = $src;
                        $remaining = ((count($source) - static::UNCOMPRESSED_END - $src + 1 - 1) > 255 ? 255 : (count($source) - static::UNCOMPRESSED_END - $src + 1 - 1));

                        $src += 4;
                        if ($source[$o + $src - $old_src] == $source[$src]) {
                            $src++;
                            if ($source[$o + $src - $old_src] == $source[$src]) {
                                $src++;
                                while ($source[$o + ($src - $old_src)] == $source[$src] && ($src - $old_src) < $remaining)
                                    $src++;
                            }
                        }

                        $matchlen = $src - $old_src;

                        $hash <<= 4;
                        if ($matchlen < 18) {
                            $f = $hash | ($matchlen - 2);
                            // Neither Java nor C# wants to inline fast_write
                            $destination[$dst + 0] = static::logical_right_shift($f, 0 * 8);
                            $destination[$dst + 1] = static::logical_right_shift($f, 1 * 8);
                            $dst += 2;
                        } else {
                            $f = $hash | ($matchlen << 16);
                            static::fast_write($destination, $dst, $f, 3);
                            $dst += 3;
                        }
                    }
                    $lits = 0;
                    $fetch = (int)static::fast_read($source, $src, 3);
                } else {
                    $lits++;
                    $hash_counter[$hash] = 1;
                    $destination[$dst] = $source[$src];
                    $cword_val = static::logical_right_shift($cword_val, 1);
                    $src++;
                    $dst++;
                    $fetch = (static::logical_right_shift($fetch, 8) & 0xffff) | ((($source[$src + 2]) & 0xff) << 16);
                }
            } else {
                $fetch = (int)static::fast_read($source, $src, 3);

                $matchlen = 0;
                $k = 0;
                $m = 0;
                $best_k = 0;

                $remaining = ((count($source) - static::UNCOMPRESSED_END - $src + 1 - 1) > 255 ? 255 : (count($source) - static::UNCOMPRESSED_END - $src + 1 - 1));
                $hash = (static::logical_right_shift($fetch, 12) ^ $fetch) & (static::HASH_VALUES - 1);

                $c = $hash_counter[$hash];
                $matchlen = 0;
                $offset2 = 0;
                for ($k = 0; $k < static::QLZ_POINTERS_3 && ($c > $k || $c < 0); $k++) {
                    $o = $hashtable[$hash][$k];
                    if ($fetch == $source[$o] && static::logical_right_shift($fetch, 8) == $source[$o + 1] &&
                        static::logical_right_shift($fetch, 16) == $source[$o + 2] && $o < $src - static::MINOFFSET) {
                        $m = 3;
                        while ($source[$o + $m] == $source[$src + $m] && $m < $remaining)
                            $m++;
                        if (($m > $matchlen) || ($m == $matchlen && $o > $offset2)) {
                            $offset2 = $o;
                            $matchlen = $m;
                            $best_k = $k;
                        }
                    }
                }
                $o = $offset2;
                $hashtable[$hash][$c & (static::QLZ_POINTERS_3 - 1)] = $src;
                $c++;
                $hash_counter[$hash] = $c;

                if ($matchlen >= 3 && $src - $o < 131071) {
                    $offset = $src - $o;
                    for ($u = 1; $u < $matchlen; $u++) {
                        $fetch = (int)static::fast_read($source, $src + $u, 3);
                        $hash = (static::logical_right_shift($fetch, 12) ^ $fetch) & (static::HASH_VALUES - 1);
                        $c = $hash_counter[$hash]++;
                        $hashtable[$hash][$c & (static::QLZ_POINTERS_3 - 1)] = $src + $u;
                    }

                    $src += $matchlen;
                    $cword_val = (static::logical_right_shift($cword_val, 1) | 0x80000000);

                    if ($matchlen == 3 && $offset <= 63) {
                        static::fast_write($destination, $dst, $offset << 2, 1);
                        $dst++;
                    } else if ($matchlen == 3 && $offset <= 16383) {
                        static::fast_write($destination, $dst, ($offset << 2) | 1, 2);
                        $dst += 2;
                    } else if ($matchlen <= 18 && $offset <= 1023) {
                        static::fast_write($destination, $dst, (($matchlen - 3) << 2) | ($offset << 6) | 2, 2);
                        $dst += 2;
                    } else if ($matchlen <= 33) {
                        static::fast_write($destination, $dst, (($matchlen - 2) << 2) | ($offset << 7) | 3, 3);
                        $dst += 3;
                    } else {
                        static::fast_write($destination, $dst, (($matchlen - 3) << 7) | ($offset << 15) | 3, 4);
                        $dst += 4;
                    }
                } else {
                    $destination[$dst] = $source[$src];
                    $cword_val = static::logical_right_shift($cword_val, 1);
                    $src++;
                    $dst++;
                }
            }
        }

        while ($src <= count($source) - 1) {
            if (($cword_val & 1) == 1) {
                static::fast_write($destination, $cword_ptr, (static::logical_right_shift($cword_val, 1) | 0x80000000), 4);
                $cword_ptr = $dst;
                $dst += static::CWORD_LEN;
                $cword_val = 0x80000000;
            }

            $destination[$dst] = $source[$src];
            $src++;
            $dst++;
            $cword_val = static::logical_right_shift($cword_val, 1);
        }
        while (($cword_val & 1) != 1) {
            $cword_val = static::logical_right_shift($cword_val, 1);
        }
        static::fast_write($destination, $cword_ptr, (static::logical_right_shift($cword_val, 1) | 0x80000000), static::CWORD_LEN);
        static::write_header($destination, $level, true, count($source), $dst);

        $d2 = array_fill(0, $dst, 0);
        static::arraycopy($destination, 0, $d2, 0, $dst);
        return $d2;
    }

    static function fast_read($a, int $i, int $numbytes)
    {
        $l = 0;
        for ($j = 0; $j < $numbytes; $j++)
            $l |= ((((int)$a[$i + $j]) & 0xff) << $j * 8);
        return $l;
    }

    static function logical_right_shift($int, $shft)
    {
        assert($int > 0);
        return ($int >> $shft)   //Arithmetic right shift
            & (PHP_INT_MAX >> (($shft - 1) < 0 ? 0 : $shft - 1));   //Deleting unnecessary bits
    }


    /**
     * @param $a
     * @param int $i
     * @param $value
     * @param int $numbytes
     */
    static function fast_write(&$a, int $i, $value, int $numbytes)
    {
        for ($j = 0; $j < $numbytes; $j++) {
            $a[$i + $j] = (static::logical_right_shift($value, ($j * 8)));
        }
    }

    static public function decompress($source)
    {
        $size = static::sizeDecompressed($source);
        assert($size != 0);
        $src = static::headerLen($source);
        $dst = 0;
        $cword_val = 1;
        $destination = array_fill(0, $size, 0);
        $hashtable = array_fill(0, 4096, 0);
        $hash_counter = array_fill(0, 4096, 0);
        $last_matchstart = $size - static::UNCONDITIONAL_MATCHLEN - static::UNCOMPRESSED_END - 1;
        $last_hashed = -1;
        $fetch = 0;

        $level = static::logical_right_shift($source[0], 2) & 0x3;

        if ($level != 1 && $level != 3)
            throw new \Exception("Php version only supports level 1 and 3");

        if (($source[0] & 1) != 1) {
            $d2 = array_fill(0, $size, 0);
            static::arraycopy($source, static::headerLen($source), $d2, 0, $size);
            return $d2;
        }

        for (; ;) {
            if ($cword_val == 1) {
                $cword_val = (int)static::fast_read($source, $src, 4);
                $src += 4;
                if ($dst <= $last_matchstart) {
                    if ($level == 1)
                        $fetch = (int)static::fast_read($source, $src, 3);
                    else
                        $fetch = (int)static::fast_read($source, $src, 4);
                }
            }

            if (($cword_val & 1) == 1) {

                $cword_val = static::logical_right_shift($cword_val, 1);

                if ($level == 1) {
                    $hash = static::logical_right_shift($fetch, 4) & 0xfff;
                    $offset2 = $hashtable[$hash];

                    if (($fetch & 0xf) != 0) {
                        $matchlen = ($fetch & 0xf) + 2;
                        $src += 2;
                    } else {
                        $matchlen = ($source[$src + 2]) & 0xff;
                        $src += 3;
                    }
                } else {
                    if (($fetch & 3) == 0) {
                        $offset = static::logical_right_shift(($fetch & 0xff), 2);
                        $matchlen = 3;
                        $src++;
                    } else if (($fetch & 2) == 0) {
                        $offset = static::logical_right_shift(($fetch & 0xffff), 2);
                        $matchlen = 3;
                        $src += 2;
                    } else if (($fetch & 1) == 0) {
                        $offset = static::logical_right_shift(($fetch & 0xffff), 6);
                        $matchlen = (static::logical_right_shift($fetch, 2) & 15) + 3;
                        $src += 2;
                    } else if (($fetch & 127) != 3) {
                        $offset = static::logical_right_shift($fetch, 7) & 0x1ffff;
                        $matchlen = (static::logical_right_shift($fetch, 2) & 0x1f) + 2;
                        $src += 3;
                    } else {
                        $offset = static::logical_right_shift($fetch, 15);
                        $matchlen = (static::logical_right_shift($fetch, 7) & 255) + 3;
                        $src += 4;
                    }
                    $offset2 = $dst - $offset;
                }

                $destination[$dst + 0] = $destination[$offset2 + 0];
                $destination[$dst + 1] = $destination[$offset2 + 1];
                $destination[$dst + 2] = $destination[$offset2 + 2];

                for ($i = 3; $i < $matchlen; $i += 1) {
                    $destination[$dst + $i] = $destination[$offset2 + $i];
                }
                $dst += $matchlen;

                if ($level == 1) {
                    $fetch = (int)static::fast_read($destination, $last_hashed + 1, 3); // destination[last_hashed + 1] | ($destination[last_hashed + 2] << 8) | ($destination[last_hashed + 3] << 16);
                    while ($last_hashed < $dst - $matchlen) {
                        $last_hashed++;
                        $hash = (static::logical_right_shift($fetch, 12) ^ $fetch) & (static::HASH_VALUES - 1);
                        $hashtable[$hash] = $last_hashed;
                        $hash_counter[$hash] = 1;
                        $fetch = static::logical_right_shift($fetch, 8) & 0xffff | (($destination[$last_hashed + 3]) & 0xff) << 16;
                    }
                    $fetch = (int)static::fast_read($source, $src, 3);
                } else {
                    $fetch = (int)static::fast_read($source, $src, 4);
                }
                $last_hashed = $dst - 1;
            } else {
                if ($dst <= $last_matchstart) {
                    $destination[$dst] = $source[$src];
                    $dst += 1;
                    $src += 1;
                    $cword_val = static::logical_right_shift($cword_val, 1);

                    if ($level == 1) {
                        while ($last_hashed < $dst - 3) {
                            $last_hashed++;
                            $fetch2 = (int)static::fast_read($destination, $last_hashed, 3);
                            $hash = (static::logical_right_shift($fetch2, 12) ^ $fetch2) & (static::HASH_VALUES - 1);
                            $hashtable[$hash] = $last_hashed;
                            $hash_counter[$hash] = 1;
                        }
                        $fetch = $fetch >> 8 & 0xffff | (((int)$source[$src + 2]) & 0xff) << 16;
                    } else {
                        $fetch = $fetch >> 8 & 0xffff | (((int)$source[$src + 2]) & 0xff) << 16 | (((int)$source[$src + 3]) & 0xff) << 24;
                    }
                } else {
                    while ($dst <= $size - 1) {
                        if ($cword_val == 1) {
                            $src += static::CWORD_LEN;
                            $cword_val = 0x80000000;
                        }

                        $destination[$dst] = $source[$src];
                        $dst++;
                        $src++;
                        $cword_val = static::logical_right_shift($cword_val, 1);
                    }
                    return array_values($destination);
                }
            }
        }
    }
}
