<?php
require_once 'PHPUnit/Framework.php';
require_once './Haml.php';

class HamlTest extends PHPUnit_Framework_TestCase
{
    private $h;
    public function __construct() { $this->h = new Haml('context'); }

    public function tests() {
      $d = opendir('./tests');
      while ($f = readdir($d)) {
        if ($f == '.' || $f == '..') continue;
        $f_name = substr($f,0,strlen($f)-5);
        if (substr($f,strlen($f)-4,4) != 'haml') continue;

        $input = file_get_contents('./tests/'.$f);
        $result = file_get_contents('./tests/'.$f_name.'.php');

        $this->assertEquals($result,$this->h->parse($input));
        $this->assertEquals($result,Haml::parse2($input,'context'));
      }
    }
}

function context($var) { return 'get_var(\''.$var.'\')'; }
?>
