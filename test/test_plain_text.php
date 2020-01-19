<?php
declare(strict_types=1);

require '../src/QuickLZ.php';

use VergeIT\QuickLZ;
use PHPUnit\Framework\TestCase;

final class CompressPlainText extends TestCase
{
    function writeByteArray($ptr, $buff) {
        for ($i = 0; $i < count($buff); $i++) {
            fwrite($ptr, pack('C', $buff[$i]));
        }
    }
    public function testCanBeCreatedFromValidEmailAddress(): void
    {
        $original_data = array_values(unpack('c*', file_get_contents('test_plain_text.php')));
        $data = QuickLZ::compress($original_data,1);

        $fh = fopen('test_out.qp', 'w+');
        $this->writeByteArray($fh, $data);
        fclose($fh);
        $buff = QuickLZ::decompress($data);
        $this->assertEquals($original_data, $buff);


    }

    public function testUnzipFile()
    {

    }

}

