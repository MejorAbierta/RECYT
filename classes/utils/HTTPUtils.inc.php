<?php

namespace CalidadFECYT\classes\utils;

class HTTPUtils {
    public static function sendStringAsFile($string, $contentType, $filename) {
        header("Content-Type: text/plain");
        header('Content-Length: ' . strlen($string));
        header('Accept-Ranges: none');
        header("Content-Disposition: attachment; filename=\"$filename\"");
        header('Cache-Control: private');
        header('Pragma: public');
        echo $string;
    }
}