<?php
  class Haml {

    const LITERAL = '[-\w\$_0-9\[\]]+|<\?.*?\?>';
    const _PHP_VAR = '/^<\?.*?\?>$/';
    const _VAR = '/^(\$[\w_0-9\[\]]+|<\?.*?\?>)$/';
    const ELEMENT = '/^[\w_][-\w_0-9]*$/';
    const _PHP = '<PHP>';
    protected $_;
    protected $_stack;
    protected $_context;
    protected $_unparse;
    protected $_was_php;
    protected $_deep;
    protected $_eval_context;
    public function __construct($context = null, $eval_context = null) {
      $this->_context = $context;
      $this->_eval_context = $eval_context;
      $this->init();
    }

    public function init() {
      $this->_ = '';
      $this->_stack = array();
      $this->_unparse = false;
      $this->_deep = 0;
    }

    public function parse($text) {
      $this->init();

      $lines = array_map(array($this,'calcLine'),explode("\n",$text));
      array_push($lines,array(0,''));

      $count = count($lines);
      for ($i = 0; $i < $count; ++$i) {
        $increase = false;
        if (!$this->_unparse) {
          $this->popStack($lines[$i][0]);
          $increase = ($i < $count - 1) && ($lines[$i + 1][0] > $this->_deep);
        }
        $this->_ .= $this->parseLine($lines[$i][1],$increase);
      }
      return $this->_;
    }

    public static function parse2($text, $context = null, $eval_context = null) {
      $h = new Haml($context,$eval_context);
      return $h->parse($text);
    }

    protected function popStack($current_deep) {
      $deep = $this->_deep;
      $this->_deep = $current_deep;

      while ($deep >= $this->_deep) {
        if (empty($this->_stack)) return;
        $element = array_pop($this->_stack);
        $tag = $element[1];
        $deep = $element[0];
        if ($deep < $this->_deep) {
          $this->pushStack($tag,$deep);
          return;
        }
        $this->_ .= ($tag ==  self::_PHP) ? '<?php }?>' : ('</'.$tag.'>');
      } 
    }

    protected function calcLine($line) {
      return array(strlen($line) - strlen(ltrim($line)),preg_replace("/\r+$/",'',$line));
    }

    protected function parseLine($line,$increase = false) {
      if ($this->_unparse && ltrim($line) != $this->_unparse) return $line."\n";
      if ($this->_unparse) {
        if ($this->_unparse == '?>') $this->_ .= '?>';
        $this->_unparse = false;
        return '';
      }

      if (empty($this->_) && substr($line,0,3) == '!!!') return $this->parseLineDoctype($line)."\n";
      #check unparse
      if (preg_match('|^\s*<<<(.+)$|',$line,$res) != 0) {
        $this->_unparse = $res[1];
        $this->_ .= "\n";
        return '';
      }
      #check PHP injection
      if (preg_match('|^\s*<\?php$|', $line) != 0) {
        $this->_unparse = '?>';
        $this->_ .= "<?php\n";
        return '';
      }
      
      if (preg_match('|^\s*//|',$line) != 0) return '';
      if (preg_match('/^\s*\- (.*)$/',$line) != 0) {
        if ($increase) $this->pushStack(self::_PHP);
        return '<?php '.substr(ltrim($line),2).($increase ? '{' : '').'?>';
      }
      if (preg_match('/^\s*\-= (.*)$/',$line,$arr) != 0) return $this->_echo($arr[1]);
      if (preg_match('|^\s*\-/$|',$line) != 0) return "\n";
      if (preg_match('/^(\s*)\-e (.*)$/',$line,$arr) != 0) return $this->parseLine($arr[1].($this->_eval($arr[2])),$increase);
      if (preg_match('/^\s*\\\\/',$line) != 0) return substr(ltrim($line),1);
      if (preg_match('/^\s*(?:%('.self::LITERAL.'))?((?:\.(?:'.self::LITERAL.'))*)(?:#('.self::LITERAL.'))?(?:\{([^\}]*)\})?(\=)?(.*)$/',$line,$arr) != 0 && (!empty($arr[1]) || !empty($arr[2]) || !empty($arr[3]) || !empty($arr[4]) || !empty($arr[5]))) return ($this->parseLineCommon($line,$arr));
      if (preg_match('|^\s*/|',$line) != 0) return $this->parseLineComment($line);
      return ltrim($line);
    }

    protected function parseLineCommon($line,$arr) {
      $line = ltrim($line);

      $tag = empty($arr[1]) ? 'div' : $this->parseElement($arr[1]);
      if ($tag === false) return $line;

      $classes = empty($arr[2]) ? false : explode('.',substr($arr[2],1));
      if ($classes) {
        $classes = array_map(array($this,'parseElement'),$classes);
        foreach ($classes as &$class)
          if ($class === false) return $line;
        $classes = implode(' ',$classes);
      }
      if (!empty($arr[3])) {
        $id = $this->parseElement($arr[3]);
        if ($id === false) return $line;
      } else
        $id = false;

      $params = ltrim($arr[4]);
      if (!empty($params)) {
        $params_result = array();
        $regexp = '/^('.self::LITERAL.')\="([^"]*)"/';
        while (preg_match($regexp,$params,$param) != 0) {
          $attr = $this->parseElement($param[1]);
          if ($attr === false) return $line;
          $value = $param[2];
          if (preg_match(self::_VAR,$value) != 0) $value = $this->getVariable($value);
          array_push($params_result,' '.$attr.'="'.$value.'"');
          $params = ltrim(preg_replace($regexp,'',$params));
        }
        if (!empty($params)) return $line;
        $params = $params_result;
      }else
        $params = false;

      $close_tag = in_array($tag,array('br', 'hr', 'link', 'meta', 'img', 'input'));

      $is_echo = ($arr[5] == '=');
      $content = ltrim($arr[6]);
      if ($close_tag && ($is_echo || !empty($content))) return $line;

      if ($is_echo) $content = $this->_echo($content);

      $result = '<'.$tag.($id ? (' id="'.$id.'"') : '').($classes ? (' class="'.$classes.'"') : '').($params ? implode('',$params) : '');
      if ($close_tag)
        $result .= ' />';
      else {
        $this->pushStack($tag);
        $result .= '>'.$content;
      }
      return $result;
    }

    protected function parseLineDoctype($line) {
      if (trim(substr($line,0,4)) == '!!!') {
        $temp = trim(substr($line,3));
        if ($temp == 'XML') return '<?xml version="1.0" encoding="utf-8" ?>';
        if ($temp == '1.1') return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">';
        if ($temp == 'Strict') return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">';
        if ($temp == '') return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">';
      } elseif (trim(substr($line,0,5)) == '!!!!') {
        $temp = trim(substr($line,4));
        if ($temp == 'Transitional') return '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">';
        if ($temp == 'Frameset') return '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Frameset//EN" "http://www.w3.org/TR/html4/frameset.dtd">';
        if ($temp == '') return '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">';
      }
      return $line;
    }

    protected function parseLineComment($line) { return '<!--'.substr(ltrim($line),1).'-->'; }

    protected function parseElement($element) {
      if (preg_match(self::_VAR,$element) != 0) $element = $this->getVariable($element);
      elseif (preg_match(self::ELEMENT,$element) == 0) return false;
      return $element;
    }

    protected function getVariable($var) {
      if (preg_match(self::_PHP_VAR,$var) != 0) return $var;
      $str = is_null($this->_context) ? $var : call_user_func_array($this->_context,array(substr($var,1)));
      return $this->_echo($str);
    }

    protected function _echo($str) {
      return '<?php echo '.$str.';?>';
    }

    protected function _eval($str) {
      return is_null($this->_eval_context) ? eval($str) : call_user_func_array($this->_eval_context,array($str));
    }

    protected function pushStack($element, $deep = null) {
      array_push($this->_stack,array(is_null($deep) ? $this->_deep : $deep,$element));
    }
  }
?>
