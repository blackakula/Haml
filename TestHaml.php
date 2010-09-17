<?php
require_once 'PHPUnit/Framework.php';
require_once './Haml.php';

class HamlTest extends PHPUnit_Framework_TestCase
{
    private $context;
    public function __construct() { $this->context = new Context(); }

    public function tests() {
      foreach (array('comments','escape','mix','php','single_tags','unparse') as $f) {
        $input = file_get_contents('./tests/'.$f.'.haml');
        $result = file_get_contents('./tests/'.$f.'.php');

        foreach (array(array('Context','context2'),array($this->context,'context1'),'context') as $context) {
          $h = new Haml($context);
          $this->assertEquals($result,$h->parse($input));
          $this->assertEquals($result,Haml::parse2($input,$context));
        }
      }
    }

    public function testEval() {
      foreach (array(
            'eval_global' => '_eval',
            'eval_obj' => array($this->context,'eval1'),
            'eval_class' => array(Context,'eval2')
                  ) as $k => $eval_context) {
        $input = file_get_contents('./tests/'.$k.'.haml');
        $result = file_get_contents('./tests/'.$k.'.php');

        $h = new Haml(null,$eval_context);
        $this->assertEquals($result,$h->parse($input));
        $this->assertEquals($result,Haml::parse2($input,null,$eval_context));
      }
    }
}

function global_tag() { return '%global.tag'; }
function context($var) { return 'get_var(\''.$var.'\')'; }
function _eval($str) { return eval('return '.$str.';'); }

class Context {

  public function _tag($class = 'default_class') { return '%tag.'.$class; }
  public static function tag($class, $attr = 'default_attr') { return '%static.'.$class.'{attr="'.$attr.'"}'; }

  public function context1($var) { return 'get_var(\''.$var.'\')'; }
  public static function context2($var) { return 'get_var(\''.$var.'\')'; }

  public function eval1($str) {
    $func = self::parseFunction($str);
    return call_user_func_array(array($this,$func[0]),$func[1]);
  }
  public static function eval2($str) {
    $func = self::parseFunction($str);
    return call_user_func_array(array(self,$func[0]),$func[1]);
  }

  private static function parseFunction($str) {
    if (preg_match('/^\s*([\w_][\w_0-9]*)\((.*)\)\s*$/',$str,$params) == 0) return false;
    $array = eval('return array('.$params[2].');');
    if (is_null($array) || $array === false) return false;
    return array($params[1],$array);
  }
}
?>
