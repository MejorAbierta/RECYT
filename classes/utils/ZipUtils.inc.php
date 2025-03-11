<?php

namespace CalidadFECYT\classes\utils;

class ZipUtils {

    public static function zip($files, $directories, $outDirectory) {
        $zipFile = new \PhpZip\ZipFile();
        try{
            foreach ($files as $file) {
                $zipFile->addFile($file);
            }
            foreach ($directories as $dir) {
                $zipFile->addDirRecursive($dir);
            }
            $zipFile->saveAsFile($outDirectory); 
            $zipFile->close(); 
        }
        catch(\PhpZip\Exception\ZipException $e){
            throw new \Exception("ZipException error: " . $e->getMessage());
        }
        finally{
            $zipFile->close();
        }
    }

    public static function unzip($zipInput, $outDirectory) {
        $zipFile = new \PhpZip\ZipFile();
        try {
            $zipFile->openFile($zipInput); 
            $zipFile->extractTo($outDirectory); 
            $zipFile->close(); 
        }
        catch(\PhpZip\Exception\ZipException $e) {
            throw new \Exception("ZipException error: " . $e->getMessage());
        }
        finally {
            $zipFile->close();
        }
    }
}