<?php
/*
 * @author Airmanbzh
 */

namespace HtmlGenerator;

use ArrayAccess;

if (!defined('ENT_XML1')) {
    define('ENT_XML1', 16);
}
if (!defined('ENT_XHTML')) {
    define('ENT_XHTML', 32);
}


class Markup implements ArrayAccess {
    /** @var boolean Specifies if attribute values and text input should be protected from XSS injection */
    public static bool $avoidXSS = false;

    /** @var int The language convention used for XSS avoiding */
    public static int $outputLanguage = ENT_XML1;

    protected static ?Markup $instance = null;

    protected ?Markup $top = null;
    public Markup|HtmlTag|null $parent = null {
        get {
            return $this->parent;
        }
    }

    protected mixed $tag = null;
    public ?array $attributeList = null;
    protected ?array $classList = null;

    protected ?array $content = null;
    protected string $text = '';

    protected bool $autoclosed = false;

    protected array $autocloseTagsList = array();

    /**
     * Constructor
     * @param mixed $tag
     * @param Markup|null $top
     * @return static instance
     */
    protected function __construct(mixed $tag, ?Markup $top = null) {
        $this->tag = $tag;
        $this->top =& $top;
        $this->attributeList = array();
        $this->classList = array();
        $this->content = array();
        $this->autoclosed = in_array($this->tag, $this->autocloseTagsList);
        $this->text = '';
        return $this;
    }

    /**
     * Builds markup from static context
     * @param string $tag The tag name
     * @param array $content The content of the current tag, first argument can be an array containing the attributes
     * @return static
     */
    public static function __callStatic(string $tag, array $content) {
        return self::createElement($tag)
            ->attr(count($content) && is_array($content[0]) ? array_pop($content) : array())
            ->text(implode('', $content));
    }

    /**
     * Add a children to the current element
     * @param string $tag The name of the tag
     * @param array $content The content of the current tag, first argument can be an array containing the attributes
     * @return Markup instance
     */
    public function __call(string $tag, array $content) {
        return $this
            ->addElement($tag)
            ->attr(count($content) && is_array($content[0]) ? array_pop($content) : array())
            ->text(implode('', $content));
    }

    /**
     * Alias for getParent()
     * @return Markup|null
     */
    public function __invoke(): ?Markup {
        return $this->parent;
    }

    /**
     * Create a new Markup
     * @param string $tag
     * @return static instance
     */
    public static function createElement(string $tag = ''): static {
        self::$instance = new static($tag);
        return self::$instance;
    }

    /**
     *
     * Add element at an existing Markup
     * @param string|Markup $tag
     * @return static instance
     */
    public function addElement(Markup|string $tag = ''): static {
        $htmlTag = ($tag instanceof self) ? clone $tag : new static($tag);
        $htmlTag->top = $this->getTop();
        $htmlTag->parent = &$this;

        $this->content[] = $htmlTag;
        return $htmlTag;
    }

    /**
     * (Re)Define an attribute or many attributes
     * @param array|string $attribute
     * @param string|null $value
     * @return static instance
     */
    public function set(array|string $attribute, ?string $value = null): Markup {
        if (is_array($attribute)) {
            foreach ($attribute as $key => $value) {
                $this[$key] = $value;
            }
        } else {
            $this[$attribute] = $value;
        }
        return $this;
    }

    /**
     * alias to method "set"
     * @param array|string $attribute
     * @param string|null $value
     * @return static instance
     */
    public function attr(array|string $attribute, ?string $value = null): Markup {
        return call_user_func_array(array($this, 'set'), func_get_args());
    }

    /**
     * Checks if an attribute is set for this tag and not null
     *
     * @param string $offset The attribute to test
     * @return boolean The result of the test
     */
    public function offsetExists($offset): bool {
        return isset($this->attributeList[$offset]);
    }

    /**
     * Returns the value the attribute set for this tag
     *
     * @param string $offset The attribute to get
     * @return mixed The stored result in this object
     */
    public function offsetGet($offset): mixed {
        return $this->offsetExists($offset) ? $this->attributeList[$offset] : null;
    }

    /**
     * Sets the value an attribute for this tag
     *
     * @param string $offset The attribute to set
     * @param mixed $value The value to set
     * @return void
     */
    public function offsetSet($offset, mixed $value): void {
        $this->attributeList[$offset] = $value;
    }

    /**
     * Removes an attribute
     *
     * @param mixed $offset The attribute to unset
     * @return void
     */
    public function offsetUnset(mixed $offset): void {
        if ($this->offsetExists($offset)) {
            unset($this->attributeList[$offset]);
        }
    }

    /**
     *
     * Define text content
     * @param string $value
     * @return static instance
     */
    public function text(string $value): static {
        $this->addElement()->text = static::$avoidXSS ? static::unXSS($value) : $value;
        return $this;
    }

    /**
     * Returns the top element
     * @return Markup|null
     */
    public function getTop(): ?static {
        return $this->top === null ? $this : $this->top;
    }

    /**
     * Return first child of parent of a current object
     */
    public function getFirst() {
        return is_null($this->parent) ? null : $this->parent->content[0];
    }

    /**
     * Return a previous element or itself
     *
     * @return static instance
     */
    public function getPrevious(): Markup|static {
        $prev = $this;
        if (!is_null($this->parent)) {
            foreach ($this->parent->content as $c) {
                if ($c === $this) {
                    break;
                }
                $prev = $c;
            }
        }
        return $prev;
    }

    /**
     * @return static last child of parent of a current object
     */
    public function getNext(): static {
        $next = null;
        $find = false;
        if (!is_null($this->parent)) {
            foreach ($this->parent->content as $c) {
                if ($find) {
                    $next = &$c;
                    break;
                }
                if ($c == $this) {
                    $find = true;
                }
            }
        }
        return $next;
    }

    /**
     * @return Markup|null last child of parent of a current object
     */
    public function getLast(): ?static {
        return is_null($this->parent) ? null : $this->parent->content[count($this->parent->content) - 1];
    }

    /**
     * @return Markup|null return parent or null
     */
    public function remove(): ?static {
        $parent = $this->parent;
        if (!is_null($parent)) {
            foreach ($parent->content as $key => $value) {
                if ($parent->content[$key] == $this) {
                    unset($parent->content[$key]);
                    return $parent;
                }
            }
        }
        return null;
    }

    /**
     * Generation method
     * @return string
     */
    public function __toString() {
        return $this->getTop()->toString();
    }

    /**
     * Generation method
     * @return string
     */
    public function toString(): string {
        $string = '';
        if (!empty($this->tag)) {
            $string .= '<' . $this->tag;
            $string .= $this->attributesToString();
            if ($this->autoclosed) {
                $string .= '/>';
            } else {
                $string .= '>' . $this->contentToString() . '</' . $this->tag . '>';
            }
        } else {
            $string .= $this->text;
            $string .= $this->contentToString();
        }
        return $string;
    }

    /**
     * return current list of attribute as a string $key="$val" $key2="$val2"
     * @return string
     */
    protected function attributesToString(): string {
        $string = '';
        $XMLConvention = in_array(static::$outputLanguage, array(ENT_XML1, ENT_XHTML));
        if (!empty($this->attributeList)) {
            foreach ($this->attributeList as $key => $value) {
                if ($value !== null && ($value !== false || $XMLConvention)) {
                    $string .= ' ' . $key;
                    if ($value === true) {
                        if ($XMLConvention) {
                            $value = $key;
                        } else {
                            continue;
                        }
                    }
                    $string .= '="' . implode(
                            ' ',
                            array_map(
                                static::$avoidXSS ? 'static::unXSS' : 'strval',
                                is_array($value) ? $value : array($value)
                            )
                        ) . '"';
                }
            }
        }
        return $string;
    }

    /**
     * return a current list of content as a string
     * @return string
     */
    protected function contentToString(): string {
        $string = '';
        if (!is_null($this->content)) {
            foreach ($this->content as $c) {
                $string .= $c->toString();
            }
        }

        return $string;
    }

    /**
     * Protects value from XSS injection by replacing some characters by XML / HTML entities
     * @param string $input The unprotected value
     * @return string A safe string
     */
    public static function unXSS(string $input): string {
        if (version_compare(phpversion(), '5.4', '<')) {
            $return = htmlspecialchars($input);
        } else {
            $return = htmlentities($input, ENT_QUOTES | ENT_DISALLOWED | static::$outputLanguage);
        }

        return $return;
    }
}
