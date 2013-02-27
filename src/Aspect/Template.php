<?php
/*
 * This file is part of Aspect.
 *
 * (c) 2013 Ivan Shalganov
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Aspect;
use Aspect;

/**
 * Template compiler
 *
 * @package    aspect
 * @author     Ivan Shalganov <owner@bzick.net>
 */
class Template extends Render {

    const DENY_ARRAY = 1;
    const DENY_MODS = 2;

    /**
     * @var int shared counter
     */
    public $i = 1;
    /**
     * Template PHP code
     * @var string
     */
    public $_body;

    /**
     * @var array of macros
     */
    public $macros = array();

    /**
     * @var array of blocks
     */
    public $blocks = array();
    /**
     * Call stack
     * @var Scope[]
     */
    private $_stack = array();

    /**
     * Template source
     * @var string
     */
    private $_src;
    /**
     * @var int
     */
    private $_pos = 0;
    private $_line = 1;
    private $_trim = false;
    private $_post = array();
    /**
     * @var bool
     */
    private $_ignore = false;
    /**
     * Options
     * @var int
     */
    private $_options = 0;

    /**
     * Just factory
     *
     * @param \Aspect $aspect
     * @return Template
     */
    public static function factory(Aspect $aspect) {
        return new static($aspect);
    }

    /**
     * @param Aspect $aspect Template storage
     */
    public function __construct(Aspect $aspect) {
        $this->_aspect = $aspect;
        $this->_options = $this->_aspect->getOptions();
    }

    /**
     * Load source from provider
     * @param string $name
     * @param bool $compile
     * @return \Aspect\Template
     */
    public function load($name, $compile = true) {
        $this->_name = $name;
        if($provider = strstr($name, ":", true)) {
            $this->_scm = $provider;
            $this->_base_name = substr($name, strlen($provider));
        } else {
            $this->_base_name = $name;
        }
        $this->_provider = $this->_aspect->getProvider($provider);
        $this->_src = $this->_provider->getSource($name, $this->_time);
        if($compile) {
            $this->compile();
        }
        return $this;
    }

    /**
     * Load custom source
     * @param string $name template name
     * @param string $src template source
     * @param bool $compile
     * @return \Aspect\Template
     */
    public function source($name, $src, $compile = true) {
        $this->_name = $name;
        $this->_src = $src;
        if($compile) {
            $this->compile();
        }
        return $this;
    }

    /**
     * Convert template to PHP code
     *
     * @throws CompileException
     */
    public function compile() {
        if(!isset($this->_src)) {
            return;
        }
        $pos = 0;
        $frag = "";
        while(($start = strpos($this->_src, '{', $pos)) !== false) { // search open-char of tags
            switch($this->_src[$start + 1]) { // check next char
                case "\n": case "\r": case "\t": case " ": case "}": // ignore the tag
                $pos = $start + 1; // try find tags after the current char
                continue 2;
                case "*": // if comment block
                    $end = strpos($this->_src, '*}', $start); // finding end of the comment block
                    $_frag = substr($this->_src, $this->_pos, $start - $end); // read the comment block for precessing
                    $this->_line += substr_count($_frag, "\n"); // count skipped lines
                    $pos = $end + 1; // trying finding tags after the comment block
                    continue 2;
            }
            $end = strpos($this->_src, '}', $start); // search close-char of the tag
            if(!$end) { // if unexpected end of template
                throw new CompileException("Unclosed tag in line {$this->_line}", 0, 1, $this->_name, $this->_line);
            }
            $frag .= substr($this->_src, $this->_pos, $start - $this->_pos);  // variable $frag contains chars after last '}' and next '{'
            $tag = substr($this->_src, $start, $end - $start + 1); // variable $tag contains aspect tag '{...}'
            $this->_line += substr_count($this->_src, "\n", $this->_pos, $end - $start + 1); // count lines in $frag and $tag (using original text $code)
            $pos = $this->_pos = $end + 1; // move search-pointer to end of the tag

            if($tag[strlen($tag) - 2] === "-") { // check right trim flag
                $_tag = substr($tag, 1, -2);
                $_frag = rtrim($frag);
            } else {
                $_tag = substr($tag, 1, -1);
                $_frag = $frag;
            }
            if($this->_ignore) { // check ignore scope
                if($_tag === '/ignore') {
                    $this->_ignore = false;
                    $this->_appendText($_frag);
                } else { // still ignore
                    $frag .= $tag;
                    continue;
                }
            } else {
                $this->_appendText($_frag);
                $this->_appendCode($this->_tag($_tag));
            }
            $frag = "";
        }
        $this->_appendText(substr($this->_src, $this->_pos));
        if($this->_stack) {
            $_names = array();
            $_line = 0;
            foreach($this->_stack as $scope) {
                if(!$_line) {
                    $_line = $scope->line;
                }
                $_names[] = $scope->name.' defined on line '.$scope->line;
            }
            throw new CompileException("Unclosed block tags: ".implode(", ", $_names), 0, 1, $this->_name, $_line);
        }
        unset($this->_src);
        if($this->_post) {
            foreach($this->_post as $cb) {
                call_user_func_array($cb, array(&$this->_body, $this));
            }
        }
        $this->_body = str_replace(array('?>'.PHP_EOL.'<?php ', '?><?php'), array(PHP_EOL, ' '), $this->_body);
    }

    /**
     * Append plain text to template body
     *
     * @param string $text
     */
    private function _appendText($text) {
        $this->_body .= str_replace("<?", '<?php echo "<?"; ?>'.PHP_EOL, $text);
    }

    public static function escapeCode($code) {
        $c = "";
        foreach(token_get_all($code) as $token) {
            if(is_string($token)) {
                $c .= $token;
            } elseif($token[0] == T_CLOSE_TAG) {
                $c .= $token[1].PHP_EOL;
            } else {
                $c .= $token[1];
            }
        }
        return $c;
    }

    /**
     * Append PHP code to template body
     *
     * @param string $code
     */
    private function _appendCode($code) {
        if(!$code) {
            return;
        } else {
            $this->_body .= self::escapeCode($code);
        }
    }

    /**
     * @param callable[] $cb
     */
    public function addPostCompile($cb) {
        $this->_post[] = $cb;
    }

    /**
     * Return PHP code of template
     *
     * @return string
     */
    public function getBody() {
        return $this->_body;
    }

    /**
     * Return PHP code for saving to file
     *
     * @return string
     */
    public function getTemplateCode() {
        return "<?php \n".
            "/** Aspect template '".$this->_name."' compiled at ".date('Y-m-d H:i:s')." */\n".
            "return new Aspect\\Render(\$aspect, ".$this->_getClosureSource().", ".var_export(array(
            //"options" => $this->_options,
            "provider" => $this->_scm,
            "name" => $this->_name,
            "base_name" => $this->_base_name,
            "time" => $this->_time,
            "depends" => $this->_depends
        ), true).");\n";
    }

    /**
     * Return closure code
     * @return string
     */
    private function _getClosureSource() {
        return "function (\$tpl) {\n?>{$this->_body}<?php\n}";
    }

    /**
     * Runtime execute template.
     *
     * @param array $values input values
     * @throws CompileException
     * @return Render
     */
    public function display(array $values) {
        if(!$this->_code) {
            // evaluate template's code
            eval("\$this->_code = ".$this->_getClosureSource().";");
            if(!$this->_code) {
                throw new CompileException("Fatal error while creating the template");
            }
        }
        return parent::display($values);

    }

    /**
     * Add depends from template
     * @param Render $tpl
     */
    public function addDepend(Render $tpl) {
        $this->_depends[$tpl->getScm()][$tpl->getName()] = $tpl->getTime();
    }

    /**
     * Execute template and return result as string
     * @param array $values for template
     * @throws CompileException
     * @return string
     */
    public function fetch(array $values) {
        if(!$this->_code) {
            eval("\$this->_code = ".$this->_getClosureSource().";");
            if(!$this->_code) {
                throw new CompileException("Fatal error while creating the template");
            }
        }
        return parent::fetch($values);
    }

    /**
     * Internal tags router
     * @param string $src
     * @throws UnexpectedException
     * @throws CompileException
     * @throws SecurityException
     * @return string executable PHP code
     */
    private function _tag($src) {
        $tokens = new Tokenizer($src);
        try {
            switch($src[0]) {
                case '"':
                case '\'':
                case '$':
                    $code = "echo ".$this->parseExp($tokens).";";
                    break;
                case '#':
                    $code = "echo ".$this->parseConst($tokens);
                    break;
                case '/':
                    $code = $this->_end($tokens);
                    break;
                default:
                    if($tokens->current() === "ignore") {
                        $this->_ignore = true;
                        $tokens->next();
                        $code = '';
                    } else {
                        $code = $this->_parseAct($tokens);
                    }
            }
            if($tokens->key()) { // if tokenizer still have tokens
                throw new UnexpectedException($tokens);
            }
            if(!$code) {
                return "";
            } else {
                return "<?php\n/* {$this->_name}:{$this->_line}: {$src} */\n {$code} ?>";
            }
        } catch (ImproperUseException $e) {
            throw new CompileException($e->getMessage()." in {$this} line {$this->_line}", 0, E_ERROR, $this->_name, $this->_line, $e);
        } catch (\LogicException $e) {
            throw new SecurityException($e->getMessage()." in {$this} line {$this->_line}, near '{".$tokens->getSnippetAsString(0,0)."' <- there", 0, E_ERROR, $this->_name, $this->_line, $e);
        } catch (\Exception $e) {
            throw new CompileException($e->getMessage()." in {$this} line {$this->_line}, near '{".$tokens->getSnippetAsString(0,0)."' <- there", 0, E_ERROR, $this->_name, $this->_line, $e);
        }
    }

    /**
     * Close tag handler
     *
     * @param Tokenizer $tokens
     * @return mixed
     * @throws TokenizeException
     */
    private function _end(Tokenizer $tokens) {
        $name = $tokens->getNext(Tokenizer::MACRO_STRING);
        $tokens->next();
        if(!$this->_stack) {
            throw new TokenizeException("Unexpected closing of the tag '$name', the tag hasn't been opened");
        }
        /** @var Scope $scope */
        $scope = array_pop($this->_stack);
        if($scope->name !== $name) {
            throw new TokenizeException("Unexpected closing of the tag '$name' (expecting closing of the tag {$scope->name}, opened on line {$scope->line})");
        }
        return $scope->close($tokens);
    }

    /**
     * Parse action {action ...} or {action(...) ...}
     *
     * @static
     * @param Tokenizer $tokens
     * @throws \LogicException
     * @throws TokenizeException
     * @return string
     */
    private function _parseAct(Tokenizer $tokens) {
        if($tokens->is(Tokenizer::MACRO_STRING)) {
            $action = $tokens->getAndNext();
        } else {
            return 'echo '.$this->parseExp($tokens).';'; // may be math and boolean expression
        }

        if($tokens->is("(", T_NAMESPACE, T_DOUBLE_COLON)) { // just invoke function or static method
            $tokens->back();
            return "echo ".$this->parseExp($tokens).";";
        } elseif($tokens->is('.')) {
            $name = $tokens->skip()->get(Tokenizer::MACRO_STRING);
            if($action !== "macro") {
                $name = $action.".".$name;
            }
            return $this->parseMacro($tokens, $name);
        }

        if($act = $this->_aspect->getFunction($action)) { // call some function
            switch($act["type"]) {
                case Aspect::BLOCK_COMPILER:
                    $scope = new Scope($action, $this, $this->_line, $act, count($this->_stack), $this->_body);
                    array_push($this->_stack, $scope);
                    return $scope->open($tokens);
                case Aspect::INLINE_COMPILER:
                    return call_user_func($act["parser"], $tokens, $this);
                case Aspect::INLINE_FUNCTION:
                    return call_user_func($act["parser"], $act["function"], $tokens, $this);
                case Aspect::BLOCK_FUNCTION:
                    $scope = new Scope($action, $this, $this->_line, $act, count($this->_stack), $this->_body);
                    $scope->setFuncName($act["function"]);
                    array_push($this->_stack, $scope);
                    return $scope->open($tokens);
                default:
                    throw new \LogicException("Unknown function type");
            }
        }

        for($j = $i = count($this->_stack)-1; $i>=0; $i--) { // call function's internal tag
            if($this->_stack[$i]->hasTag($action, $j - $i)) {
                return $this->_stack[$i]->tag($action, $tokens);
            }
        }
        if($tags = $this->_aspect->getTagOwners($action)) { // unknown template tag
            throw new TokenizeException("Unexpected tag '$action' (this tag can be used with '".implode("', '", $tags)."')");
        } else {
            throw new TokenizeException("Unexpected tag $action");
        }
    }

    /**
     * Parse expressions. The mix of math operations, boolean operations, scalars, arrays and variables.
     *
     * @static
     * @param Tokenizer $tokens
     * @param bool               $required
     * @throws \LogicException
     * @throws UnexpectedException
     * @throws TokenizeException
     * @return string
     */
    public function parseExp(Tokenizer $tokens, $required = false) {
        $_exp = "";
        $brackets = 0;
        $term = false;
        $cond = false;
        while($tokens->valid()) {
            if(!$term && $tokens->is(Tokenizer::MACRO_SCALAR, '"', '`', T_ENCAPSED_AND_WHITESPACE)) {
                $_exp .= $this->parseScalar($tokens, true);
                $term = 1;
            } elseif(!$term && $tokens->is(T_VARIABLE)) {
                $pp = $tokens->isPrev(Tokenizer::MACRO_INCDEC);
                $_exp .= $this->parseVar($tokens, 0, $only_var);
                if($only_var && !$pp) {
                    $term = 2;
                } else {
                    $term = 1;
                }
            } elseif(!$term && $tokens->is('#')) {
                $term = 1;
                $_exp .= $this->parseConst($tokens);
            } elseif(!$term && $tokens->is("(")) {
                $_exp .= $tokens->getAndNext();
                $brackets++;
                $term = false;
            } elseif($term && $tokens->is(")")) {
                if(!$brackets) {
                    break;
                }
                $brackets--;
                $_exp .= $tokens->getAndNext();
                $term = 1;
            } elseif(!$term && $tokens->is(T_STRING)) {
                if($tokens->isSpecialVal()) {
                    $_exp .= $tokens->getAndNext();
                } elseif($tokens->isNext("(")) {
                    $func = $this->_aspect->getModifier($tokens->current());
                    $tokens->next();
                    $_exp .= $func.$this->parseArgs($tokens);
                } else {
                    break;
                }
                $term = 1;
            } elseif(!$term && $tokens->is(T_ISSET, T_EMPTY)) {
                $_exp .= $tokens->getAndNext();
                if($tokens->is("(") && $tokens->isNext(T_VARIABLE)) {
                    $_exp .= $this->parseArgs($tokens);
                } else {
                    throw new TokenizeException("Unexpected token ".$tokens->getNext().", isset() and empty() accept only variables");
                }
                $term = 1;
            } elseif(!$term && $tokens->is(Tokenizer::MACRO_UNARY)) {
                if(!$tokens->isNext(T_VARIABLE, T_DNUMBER, T_LNUMBER, T_STRING, T_ISSET, T_EMPTY)) {
                    break;
                }
                $_exp .= $tokens->getAndNext();
                $term = 0;
            } elseif($tokens->is(Tokenizer::MACRO_BINARY)) {
                if(!$term) {
                    throw new UnexpectedException($tokens);
                }
                if($tokens->isLast()) {
                    break;
                }
                if($tokens->is(Tokenizer::MACRO_COND)) {
                    if($cond) {
                        break;
                    }
                    $cond = true;
                } elseif ($tokens->is(Tokenizer::MACRO_BOOLEAN)) {
                    $cond = false;
                }
                $_exp .= " ".$tokens->getAndNext()." ";
                $term = 0;
            } elseif($tokens->is(Tokenizer::MACRO_INCDEC)) {
                if($term === 2) {
                    $term = 1;
                } elseif(!$tokens->isNext(T_VARIABLE)) {
                    break;
                }
                $_exp .= $tokens->getAndNext();
            } elseif($term && !$cond && !$tokens->isLast()) {
                if($tokens->is(Tokenizer::MACRO_EQUALS) && $term === 2) {
                    $_exp .= ' '.$tokens->getAndNext().' ';
                    $term = 0;
                } else {
                    break;
                }
            } else {
                break;
            }
        }

        if($term === 0) {
            throw new UnexpectedException($tokens);
        }
        if($brackets) {
            throw new TokenizeException("Brackets don't match");
        }
        if($required && $_exp === "") {
            throw new UnexpectedException($tokens);
        }
        return $_exp;
    }


    /**
     * Parse variable
     * $var.foo[bar]["a"][1+3/$var]|mod:3:"w":$var3|mod3
     *
     * @see parseModifier
     * @static
     * @param Tokenizer $tokens
     * @param int                $deny
     * @param bool               $pure_var
     * @throws \LogicException
     * @throws UnexpectedException
     * @return string
     */
    public function parseVar(Tokenizer $tokens, $deny = 0, &$pure_var = true) {
        $var = $tokens->get(T_VARIABLE);
        $pure_var = true;
        $_var = '$tpl["'.ltrim($var,'$').'"]';
        $tokens->next();
        while($t = $tokens->key()) {
            if($t === "." && !($deny & self::DENY_ARRAY)) {
                $key = $tokens->getNext();
                if($tokens->is(T_VARIABLE)) {
                    $key = "[ ".$this->parseVar($tokens, self::DENY_ARRAY)." ]";
                } elseif($tokens->is(Tokenizer::MACRO_STRING)) {
                    if($tokens->isNext("(")) {
                        $key = "[".$this->parseExp($tokens)."]";
                    } else {
                        $key = '["'.$key.'"]';
                        $tokens->next();
                    }
                } elseif($tokens->is(Tokenizer::MACRO_SCALAR, '"')) {
                    $key = "[".$this->parseScalar($tokens, false)."]";
                } else {
                    break;
                }
                $_var .= $key;
            } elseif($t === "[" && !($deny & self::DENY_ARRAY)) {
                $tokens->next();
                if($tokens->is(Tokenizer::MACRO_STRING)) {
                    if($tokens->isNext("(")) {
                        $key = "[".$this->parseExp($tokens)."]";
                    } else {
                        $key = '["'.$tokens->current().'"]';
                        $tokens->next();
                    }
                } else {
                    $key = "[".$this->parseExp($tokens, true)."]";
                }
                $tokens->get("]");
                $tokens->next();
                $_var .= $key;
            } elseif($t === "|" && !($deny & self::DENY_MODS)) {
                $pure_var = false;
                return $this->parseModifier($tokens, $_var);
            } elseif($t === T_OBJECT_OPERATOR) {
                $prop = $tokens->getNext(T_STRING);
                if($tokens->isNext("(")) {
                    if($this->_options & Aspect::DENY_METHODS) {
                        throw new \LogicException("Forbidden to call methods");
                    }
                    $pure_var = false;
                    $tokens->next();
                    $_var .= '->'.$prop.$this->parseArgs($tokens);
                } else {
                    $tokens->next();
                    $_var .= '->'.$prop;
                }
            } elseif($t === T_DNUMBER) {
                $_var .= '['.substr($tokens->getAndNext(), 1).']';
            } elseif($t === "?" || $t === "!") {
                $pure_var = false;
                $empty = ($t === "?");
                $tokens->next();
                if($tokens->is(":")) {
                    $tokens->next();
                    if($empty) {
                        return '(empty('.$_var.') ? ('.$this->parseExp($tokens, true).') : '.$_var.')';
                    } else {
                        return '(isset('.$_var.') ? '.$_var.' : ('.$this->parseExp($tokens, true).'))';
                    }
                } elseif($tokens->is(Tokenizer::MACRO_BINARY, Tokenizer::MACRO_BOOLEAN, Tokenizer::MACRO_MATH) || !$tokens->valid()) {
                    if($empty) {
                        return '!empty('.$_var.')';
                    } else {
                        return 'isset('.$_var.')';
                    }
                } else {
                    $expr1 = $this->parseExp($tokens, true);
                    if(!$tokens->is(":")) {
                        throw new UnexpectedException($tokens, null, "ternary operator");
                    }
                    $expr2 = $this->parseExp($tokens, true);
                    if($empty) {
                        return '(empty('.$_var.') ? '.$expr2.' : '.$expr1.')';
                    } else {
                        return '(isset('.$_var.') ? '.$expr1.' : '.$expr2.')';
                    }
                }
            } elseif($t === "!") {
                $pure_var = false;
                $tokens->next();
                return 'isset('.$_var.')';
            } else {
                break;
            }
        }
        return $_var;
    }

    /**
     * Parse scalar values
     *
     * @param Tokenizer $tokens
     * @param bool $allow_mods
     * @return string
     * @throws TokenizeException
     */
    public function parseScalar(Tokenizer $tokens, $allow_mods = true) {
        $_scalar = "";
        if($token = $tokens->key()) {
            switch($token) {
                case T_CONSTANT_ENCAPSED_STRING:
                case T_LNUMBER:
                case T_DNUMBER:
                    $_scalar .= $tokens->getAndNext();
                    break;
                case T_ENCAPSED_AND_WHITESPACE:
                case '"':
                    $_scalar .= $this->parseSubstr($tokens);
                    break;
                default:
                    throw new TokenizeException("Unexpected scalar token '".$tokens->current()."'");
            }
            if($allow_mods && $tokens->is("|")) {
                return $this->parseModifier($tokens, $_scalar);
            }
        }
        return $_scalar;
    }

    /**
     * Parse string with or without variable
     *
     * @param Tokenizer $tokens
     * @throws UnexpectedException
     * @return string
     */
    public function parseSubstr(Tokenizer $tokens) {
        ref: {
            if($tokens->is('"',"`")) {
                $p = $tokens->p;
                $stop = $tokens->current();
                $_str = '"';
                $tokens->next();
                while($t = $tokens->key()) {
                    if($t === T_ENCAPSED_AND_WHITESPACE) {
                        $_str .= $tokens->current();
                        $tokens->next();
                    } elseif($t === T_VARIABLE) {
                        if(strlen($_str) > 1) {
                            $_str .= '".';
                        } else {
                            $_str = "";
                        }
                        $_str .= '$tpl["'.substr($tokens->current(), 1).'"]';
                        $tokens->next();
                        if($tokens->is($stop)) {
                            $tokens->skip();
                            return $_str;
                        } else {
                            $_str .= '."';
                        }
                    } elseif($t === T_CURLY_OPEN) {
                        if(strlen($_str) > 1) {
                            $_str .= '".';
                        } else {
                            $_str = "";
                        }
                        $tokens->getNext(T_VARIABLE);
                        $_str .= '('.$this->parseExp($tokens).')';
                        /*if(!$tokens->valid()) {
                            $more = $this->_getMoreSubstr($stop);
                            //var_dump($more); exit;
                            $tokens->append("}".$more, $p);
                            var_dump("Curly", $more, $tokens->getSnippetAsString());
                            exit;
                        }*/

                        //$tokens->skip('}');
                        if($tokens->is($stop)) {
                            $tokens->next();
                            return $_str;
                        } else {
                            $_str .= '."';
                        }
                    } elseif($t === "}") {
                        $tokens->next();
                    } elseif($t === $stop) {
                        $tokens->next();
                        return $_str.'"';
                    } else {

                        break;
                    }
                }
                if($more = $this->_getMoreSubstr($stop)) {
                    $tokens->append("}".$more, $p);
                    goto ref;
                }
                throw new UnexpectedException($tokens);
            } elseif($tokens->is(T_CONSTANT_ENCAPSED_STRING)) {
                return $tokens->getAndNext();
            } elseif($tokens->is(T_ENCAPSED_AND_WHITESPACE)) {
                $p = $tokens->p;
                if($more = $this->_getMoreSubstr($tokens->curr[1][0])) {
                    $tokens->append("}".$more, $p);
                    goto ref;
                }
                throw new UnexpectedException($tokens);
            } else {
                return "";
            }
        }
    }

    /**
     * @param string $after
     * @return bool|string
     */
    private function _getMoreSubstr($after) {
        $end = strpos($this->_src, $after, $this->_pos);
        $end = strpos($this->_src, "}", $end);
        if(!$end) {
            return false;
        }
        $fragment = substr($this->_src, $this->_pos, $end - $this->_pos);
        $this->_pos = $end + 1;
        return $fragment;
    }

    /**
     * Parse modifiers
     * |modifier:1:2.3:'string':false:$var:(4+5*$var3)|modifier2:"str {$var+3} ing":$arr.item
     *
     * @param Tokenizer $tokens
     * @param                    $value
     * @throws \LogicException
     * @throws \Exception
     * @return string
     */
    public function parseModifier(Tokenizer $tokens, $value) {
        while($tokens->is("|")) {
            $mods = $this->_aspect->getModifier( $tokens->getNext(Tokenizer::MACRO_STRING) );
            $tokens->next();
            $args = array();

            while($tokens->is(":")) {
                $token = $tokens->getNext(Tokenizer::MACRO_SCALAR, T_VARIABLE, '"', Tokenizer::MACRO_STRING, "(", "[");

                if($tokens->is(Tokenizer::MACRO_SCALAR) || $tokens->isSpecialVal()) {
                    $args[] = $token;
                    $tokens->next();
                } elseif($tokens->is(T_VARIABLE)) {
                    $args[] = $this->parseVar($tokens, self::DENY_MODS);
                } elseif($tokens->is('"', '`', T_ENCAPSED_AND_WHITESPACE)) {
                    $args[] = $this->parseSubstr($tokens);
                } elseif($tokens->is('(')) {
                    $args[] = $this->parseExp($tokens);
                } elseif($tokens->is('[')) {
                    $args[] = $this->parseArray($tokens);
                } elseif($tokens->is(T_STRING) && $tokens->isNext('('))  {
                    $args[] = $tokens->getAndNext().$this->parseArgs($tokens);
                } else {
                    break;
                }
            }


            if($args) {
                $value = $mods.'('.$value.', '.implode(", ", $args).')';
            } else {
                $value = $mods.'('.$value.')';
            }
        }
        return $value;
    }

    /**
     * Parse array
     * [1, 2.3, 5+7/$var, 'string', "str {$var+3} ing", $var2, []]
     *
     * @param Tokenizer $tokens
     * @throws UnexpectedException
     * @return string
     */
    public function parseArray(Tokenizer $tokens) {
        if($tokens->is("[")) {
            $_arr = "array(";
            $key = $val = false;
            $tokens->next();
            while($tokens->valid()) {
                if($tokens->is(',') && $val) {
                    $key = true;
                    $val = false;
                    $_arr .= $tokens->getAndNext().' ';
                } elseif($tokens->is(Tokenizer::MACRO_SCALAR, T_VARIABLE, T_STRING, T_EMPTY, T_ISSET, "(", "#") && !$val) {
                    $_arr .= $this->parseExp($tokens, true);
                    $key = false;
                    $val = true;
                } elseif($tokens->is('"') && !$val) {
                    $_arr .= $this->parseSubstr($tokens);
                    $key = false;
                    $val = true;
                } elseif($tokens->is(T_DOUBLE_ARROW) && $val) {
                    $_arr .= ' '.$tokens->getAndNext().' ';
                    $key = true;
                    $val = false;
                } elseif(!$val && $tokens->is('[')) {
                    $_arr .= $this->parseArray($tokens);
                    $key = false;
                    $val = true;
                } elseif($tokens->is(']') && !$key) {
                    $tokens->next();
                    return $_arr.')';
                } else {
                    break;
                }
            }
        }
        throw new UnexpectedException($tokens);
    }

    /**
     * Parse constant
     * #Ns\MyClass::CONST1, #CONST1, #MyClass::CONST1
     *
     * @param Tokenizer $tokens
     * @return string
     * @throws ImproperUseException
     */
    public function parseConst(Tokenizer $tokens) {
        $tokens->get('#');
        $name = $tokens->getNext(T_STRING);
        $tokens->next();
        if($tokens->is(T_NAMESPACE)) {
            $name .= '\\';
            $name .= $tokens->getNext(T_STRING);
            $tokens->next();
        }
        if($tokens->is(T_DOUBLE_COLON)) {
            $name .= '::';
            $name .= $tokens->getNext(T_STRING);
            $tokens->next();
        }
        if(defined($name)) {
            return $name;
        } else {
            throw new ImproperUseException("Use undefined constant $name");
        }
    }

    /**
     * @param Tokenizer $tokens
     * @param $name
     * @return string
     * @throws ImproperUseException
     */
    public function parseMacro(Tokenizer $tokens, $name) {
        if(isset($this->macros[ $name ])) {
            $macro = $this->macros[ $name ];
            $p = $this->parseParams($tokens);
            $args = array();
            foreach($macro["args"] as $arg) {
                if(isset($p[ $arg ])) {
                    $args[ $arg ] = $p[ $arg ];
                } elseif(isset($macro["defaults"][ $arg ])) {
                    $args[ $arg ] = $macro["defaults"][ $arg ];
                } else {
                    throw new ImproperUseException("Macro '$name' require '$arg' argument");
                }
            }
            $args = $args ? '$tpl = '.Compiler::toArray($args).';' : '';
            return '$_tpl = $tpl; '.$args.' ?>'.$macro["body"].'<?php $tpl = $_tpl; unset($_tpl);';
        } else {
            var_dump($this->macros);
            throw new ImproperUseException("Undefined macro '$name'");
        }
    }

    /**
     * Parse argument list
     * (1 + 2.3, 'string', $var, [2,4])
     *
     * @static
     * @param Tokenizer $tokens
     * @throws TokenizeException
     * @return string
     */
    public function parseArgs(Tokenizer $tokens) {
        $_args = "(";
        $tokens->next();
        $arg = $colon = false;
        while($tokens->valid()) {
            if(!$arg && $tokens->is(T_VARIABLE, T_STRING, "(", Tokenizer::MACRO_SCALAR, '"', Tokenizer::MACRO_UNARY, Tokenizer::MACRO_INCDEC)) {
                $_args .= $this->parseExp($tokens, true);
                $arg = true;
                $colon = false;
            } elseif(!$arg && $tokens->is('[')) {
                $_args .= $this->parseArray($tokens);
                $arg = true;
                $colon = false;
            } elseif($arg && $tokens->is(',')) {
                $_args .= $tokens->getAndNext().' ';
                $arg = false;
                $colon = true;
            } elseif(!$colon && $tokens->is(')')) {
                $tokens->next();
                return $_args.')';
            } else {
                break;
            }
        }

        throw new TokenizeException("Unexpected token '".$tokens->current()."' in argument list");
    }

    /**
     * Parse first unnamed argument
     *
     * @param Tokenizer $tokens
     * @param string $static
     * @return mixed|string
     */
    public function parseFirstArg(Tokenizer $tokens, &$static) {
        if($tokens->is(T_CONSTANT_ENCAPSED_STRING)) {
            if($tokens->isNext('|')) {
                return $this->parseExp($tokens, true);
            } else {
                $str = $tokens->getAndNext();
                $static = stripslashes(substr($str, 1, -1));
                return $str;
            }
        } elseif($tokens->is(Tokenizer::MACRO_STRING)) {
            $static = $tokens->getAndNext();
            return '"'.addslashes($static).'"';
        } else {
            return $this->parseExp($tokens, true);
        }
    }

    /**
     * Parse parameters as $key=$value
     * param1=$var param2=3 ...
     *
     * @static
     * @param Tokenizer $tokens
     * @param array     $defaults
     * @throws \Exception
     * @return array
     */
    public function parseParams(Tokenizer $tokens, array $defaults = null) {
        $params = array();
        while($tokens->valid()) {
            if($tokens->is(Tokenizer::MACRO_STRING)) {
                $key = $tokens->getAndNext();
                if($defaults && !isset($defaults[$key])) {
                    throw new \Exception("Unknown parameter '$key'");
                }
                if($tokens->is("=")) {
                    $tokens->next();
                    $params[ $key ] = $this->parseExp($tokens);
                } else {
                    $params[ $key ] = 'true';
                }
            } elseif($tokens->is(Tokenizer::MACRO_SCALAR, '"', '`', T_VARIABLE, "[", '(')) {
                $params[] = $this->parseExp($tokens);
            } else {
                break;
            }
        }
        if($defaults) {
            $params += $defaults;
        }

        return $params;
    }
}

class CompileException extends \ErrorException {}
class SecurityException extends CompileException {}
class ImproperUseException extends \LogicException {}