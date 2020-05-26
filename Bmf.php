<?php
/**
 * Use this class to load a BMF files and write with it.
 *
 * @see http://bmf.php5.cz/?page=format
 * @author <crs@centrum.cz>
 */
class Bmf {

    /** @var header constant in the beginning of the file */
    const MAGIC_HEADER = "\xE1\xE6\xD5\x1A";

    /** @var int access from/to file */
    const ACCESS_FILE = 0;

    /** @var int access from/to memory */
    const ACCESS_MEMORY = 1;

    /** @var int used in textAsImage(), output as transparent PNG */
    const OUTPUT_PNG = 1;

    /** @var int used in textAsImage(), output as HTML <img src="data:..."> */
    const OUTPUT_HTML = 2;

    /** @var int used in textAsImage(), return image resource */
    const OUTPUT_RESOURCE = 3;

    /** @var int used in textAsImage(), return PNG raw data, base64-encoded */
    const OUTPUT_BASE64 = 4;

    /** @var bool used in toString(), include attributes */
    const TOSTRING_ATTRIBUTES = 1;

    /** @var bool used in toString(), include palette */
    const TOSTRING_PALETTE = 2;

    /** @var bool used in toString(), include chars */
    const TOSTRING_CHARS = 4;

    /** @var int way to access the module */
    public $accessMode = self::ACCESS_FILE;

    /** @var resource|null file handle for file access */
    public $fileHandle;

    /** @var int offset for reading from memory */
    public $offset = 0;

    /** @var string font's file name or raw data */
    public $font;

    /** @var int version (0x11 for v1.1) */
    public $version;

    /** @var int line height */
    public $lineHeight;

    /** @var int length over the base line in pixels (–128...127) */
    public $sizeOver;

    /** @var int length under the base line in pixels (–128...127) */
    public $sizeUnder;

    /** @var int add space after each char in pixels (–128...127) */
    public $addSpace;

    /** @var int size inner (small letters height) (–128...127) */
    public $sizeInner;

    /** @var int count of used colors */
    public $usedColors;

    /** @var int highest used color attribute */
    public $highestColor;

    /** @var string reserved 4-byte chunk */
    public $reserved;

    /** @var int number of colors in palette */
    public $colors;

    /** @var string font title */
    public $title;

    /** @var int number of characters in font */
    public $numChars;

    /** @var array of characters, each with defs and bitmap*/
    protected $tablo;

    /** @var array color palette as array of r,g,b components (0..63 each) */
    protected $palette;

    /** @var bool keep background transparent? */
    public $transparentBackground = true;

    /** @var int cached result of $this->isMonospace() */
    public $monospace = 0;

    /** @var int gap between letters when outputting text (in addition to addSpace) */
    public $letterGap = 0;

    /** @var int used in textAsImage(..., OUTPUT_HTML) - additional <img> attributes */
    public $imgAttributes = [];

    /**
     * Constructor. Load a font if a parameter is provided.
     *
     * @param string|null $font if specified, $this->load() is called
     * @param int $accessMode access mode, see self::ACCESS_* constants
     */
    function __construct($font = null, $accessMode = self::ACCESS_FILE)
    {
        $this->accessMode = $accessMode;
        if (($this->font = $font) !== null) {
            $this->load($font);
        }
    }

    /**
     * Clean up font data
     *
     * @return void
     */
    public function cleanUp()
    {
        foreach (['lineHeight', 'sizeOver', 'sizeUnder', 'addSpace', 'sizeInner', 
            'usedColors', 'highestColor', 'colors', 'numChars'] as $i) {
            $this->{$i} = 0;
        }
        $this->title = '';
        $this->palette = [];
        $this->monospace = null;
        for ($i = 0; $i < 256; $i++) {
            $this->tablo[$i] = [
                'width' => 0,
                'height' => 0,
                'relx' => 0,
                'rely' => 0,
                'shift' => 0,
                'data' => ''
            ];
        }
    }

    /**
     * Closes a font (needed for file access)
     *
     * @return void
     */
    public function close()
    {
        if ($this->accessMode == self::ACCESS_FILE) {
            fclose($this->fileHandle);
            $this->fileHandle = null;
        }
    }

    /**
     * Allocate font's palette colors to given image
     *
     * @param resource &$image
     * @return array palette of allocated font's colors
     */
    public function imagePalette(&$image)
    {
        $result[0] = imagecolorallocate($image, 0, 0, 0);
        foreach ($this->palette as $key => $value) {
            $result []= imagecolorallocate($image, $value[0], $value[1], $value[2]);
        }
        return $result;
    }

    /**
     * Write a string with the current font to given image
     *
     * @param resource &$image
     * @param int $x x-coordinate
     * @param int $y y-coordinate
     * @param string $string text to output
     * @return int width of the text written
     */
    public function imageString(&$image, $x, $y, $string)
    {
        $palette = $this->imagePalette($image);
        for ($i = 0, $x0 = $x; $i < strlen($string); $i++) {
            $letter = &$this->tablo[ord($string[$i])];
            for ($j = 0, $rely = $letter['rely']; $j < $letter['height']; $j++) { 
                for ($k = 0; $k < $letter['width']; $k++) {
                    if ($color = ord($letter['data'][$j * $letter['width'] + $k])) {
                        imagesetpixel($image, $x + $letter['relx'] + $k, $y + $rely + $j, $palette[$color]);
                    }
                }
            }
            $x += $letter['shift'] + $this->addSpace + $this->letterGap;
        }
        return $x - $x0;
    }

    /**
     * Is font monospaced (are all its characters same width)?
     *
     * @return int 0 for empty/unchecked, positive = uniform shift, negative = first character whose shift differs * -1
     */
    public function isMonospace()
    {
        $this->monospace = 0;
        for ($i = 0; $i < 256; $i++) {
            $letter = &$this->tablo[$i];
            if ($letter['shift']) { // we examine uniformity in character's shift
                if ($this->monospace) {
                    if ($letter['shift'] != $this->monospace) {
                        return ($this->monospace = -$i);
                    }
                } else {
                    $this->monospace = $letter['shift'];
                }
            }
        }
        return $this->monospace;
    }

    /**
     * Load a BMF font
     *
     * @param string $font font's file name or raw data
     * @param int $accessMode access mode, see self::ACCESS_* constants
     * @return bool success
     */
    public function load($font, $accessMode = self::ACCESS_FILE)
    {
        $this->cleanUp();
        $this->font = $font;
        if (!$this->open()
            || (strlen($buffer = $this->read(17)) != 17)
            || (substr($buffer, 0, 4) != self::MAGIC_HEADER)) {
            return false;
        }
        if (($this->version = ord($buffer[4])) > 0x11) {
            return false; // version check
        }
        $this->lineHeight = ord($buffer[5]);
        $this->sizeOver = unpack('c', $buffer[6])[1];
        $this->sizeUnder = unpack('c', $buffer[7])[1];
        $this->addSpace = unpack('c', $buffer[8])[1];
        $this->sizeInner = unpack('c', $buffer[9])[1];
        $this->usedColors = ord($buffer[10]);
        $this->highestColor = ord($buffer[11]);
        $this->reserved = unpack('L', substr($buffer, 12, 4))[1];
        $this->colors = ord($buffer[16]);
        if (strlen($buffer = $this->read($this->colors * 3)) != $this->colors * 3) { // load the palette
            return false;
        }
        for ($i = 0; $i < $this->colors; $i++) { // stretch each palette RGB component from 0..63 to 0..255
            $this->palette[$i] = [ord($buffer[$i * 3]) * 4, ord($buffer[$i * 3 + 1]) * 4, ord($buffer[$i * 3 + 2]) * 4];
        }
        $this->title = $this->read(ord($this->read(1)));
        if (($this->numChars = unpack('S', $this->read(2))[1]) > 256) {
            return false;
        }
        for ($i = 0; $i < $this->numChars; $i++) {
            if (strlen($buffer = $this->read(6)) != 6) {
                return false;
            }
            $this->tablo[$which = ord($buffer[0])] = [
                'width' => $width = ord($buffer[1]),
                'height' => $height = ord($buffer[2]),
                'relx' => unpack('c', $buffer[3])[1],
                'rely' => unpack('c', $buffer[4])[1],
                'shift' => ord($buffer[5]),
                'data' => ''
            ];
            if ($width && $height) {
                if (strlen($this->tablo[$which]['data'] = $this->read($width * $height)) != $width * $height) {
                    return false;
                }
            }
        }
        $this->close();
        return true;
    }

    /**
     * Open a font (needed for file access)
     *
     * @param int $accessMode access mode, see self::ACCESS_* constants
     * @return bool succes status
     */
    public function open($accessMode = self::ACCESS_FILE)
    {
        $this->accessMode = $accessMode;
        if ($this->accessMode == self::ACCESS_FILE) {
            if (!file_exists($this->font)) {
                return false;
            }
            return $this->fileHandle = fopen($this->font, 'r');
        }
        return true;
    }

    /**
     * Read next $bytes bytes off a font
     *
     * @param int $bytes amount of bytes to be read
     * @return string data
     */
    public function read($bytes)
    {
        if ($this->accessMode == self::ACCESS_FILE) {
            return fread($this->fileHandle, $bytes);
        } elseif ($this->accessMode == self::ACCESS_MEMORY) {
            $result = substr($font, $this->offset, $bytes);
            $this->offset += $bytes;
            return $result;
        }
    }

    /**
     * Saves (or returns) font data in the format
     *
     * @param int $accessMode access mode, see self::ACCESS_* constants
     * @return bool|string success status for file access, raw data for memory access
     */
    public function save($accessMode = self::ACCESS_FILE)
    {
        $result = self::MAGIC_HEADER;
        foreach (explode(' ', 'version lineHeight sizeOver sizeUnder addSpace sizeInner usedColors highestColor') as $i) {
            $result .= chr($this->{$i});
        }
        $result .= pack('L', $this->reserved) . chr($this->colors);
        for ($i = 0; $i < $this->colors; $i++) {
            $result .= chr($this->palette[$i][0] / 4) . chr($this->palette[$i][1] / 4) . chr($this->palette[$i][2] / 4);
        }
        $result .= chr(strlen($this->title)) . $this->title . pack('S', $this->numChars);
        for ($i = 0; $i < 256; $i++) {
            if (($w = $this->tablo[$i]['width']) * ($h = $this->tablo[$i]['height']) || ($shift = $this->tablo[$i]['shift'])) {
                $result .= chr($i) . chr($w) . chr($h) 
                    . pack('c', $this->tablo[$i]['relx']) . pack('c', $this->tablo[$i]['rely']) 
                    . chr($this->tablo[$i]['shift']) . $this->tablo[$i]['data'];
            }
        }
        if ($this->accessMode == self::ACCESS_FILE) {
            return file_put_contents($this->font, $result);
        } elseif ($this->accessMode == self::ACCESS_MEMORY) {
            return $result;
        }
    }

    /**
     * Output font's text as image
     *
     * @param string $text
     * @param int $output see self::OUTPUT_* constants
     * @return void|resource|string according to $output
     */
    public function textAsImage($text, $output = self::OUTPUT_PNG)
    {
        $width; $height;
        $this->textSize($text, $width, $height);
        $image = $this->colors < 250 ? imagecreate($width, $height) : imagecreatetruecolor($width, $height);
        imagealphablending($image, $this->transparentBackground);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, 
            imagecolorallocatealpha($image, 0, 0, 0, $this->transparentBackground ? 127 : 0));
        $this->imagePalette($image);
        $x = max(-$this->tablo[ord($text[0])]['relx'], 0);
        $this->imageString($image, $x, 0, $text);
        switch ($output) {
            case self::OUTPUT_PNG:
                header('Content-type: image/png');
                imagepng($image);
                imagedestroy($image);
                exit;
            case self::OUTPUT_BASE64:
            case self::OUTPUT_HTML:
                ob_start();
                imagepng($image);
                $result = base64_encode(ob_get_contents());
                imagedestroy($image);
                ob_end_clean();
                if ($output == self::OUTPUT_BASE64) {
                    return $result;
                } else {
                    $result = '<img src="data:image/png;base64,' . $result . '"';
                    foreach ($this->imgAttributes as $key => $value) {
                        $result .= " $key=\"" . htmlspecialchars($value, ENT_HTML5, 'UTF-8') . '"';
                    }
                    return $result . ' />';
                }
            case self::OUTPUT_RESOURCE:
                return $image;
            default:
                imagedestroy($image);
                return false;
        }
    }

    /**
     * Get width and height of text of current font
     *
     * @param string $text
     * @param int &$width
     * @param int &$height
     * @return void
     */
    public function textSize($text, &$width, &$height)
    {
        $width = 0;
        for ($i = 0; $i < strlen($text) - 1; $i++) {
            $width += $this->tablo[ord($text[$i])]['shift'] + $this->addSpace;
        }
        $width += max(-$this->tablo[ord($text[0])]['relx'], 0) 
            + $this->tablo[ord($text[strlen($text) - 1])]['relx'] 
            + $this->tablo[ord($text[strlen($text) - 1])]['width']
            + (strlen($text) - 1) * $this->letterGap; 
        $height = max($this->lineHeight, -$this->sizeOver + $this->sizeUnder, 1);
    }

    /**
     * Return font's parameters in a human-readable form.
     *
     * @param int $include see self::TOSTRING_* constants
     * @return string
     */
    public function toString($include = self::TOSTRING_ATTRIBUTES | self::TOSTRING_PALETTE | self::TOSTRING_CHARS)
    {
        $result = 'title: '  . $this->title . "\n";
        if ($include & self::TOSTRING_ATTRIBUTES) {
            foreach (['colors', 'numChars', 'lineHeight', 'sizeOver', 'sizeUnder', 'addSpace', 'sizeInner', 
                'usedColors', 'highestColor'] as $i) {
                $result .= "$i: " . $this->{$i} . "\n";
            }
        }
        if ($include & self::TOSTRING_PALETTE) {
            $result .= 'palette:';
            for ($i = 0; $i < $this->colors; $i++) {
                $color = substr('00000' . dechex(($this->palette[$i][0] << 16) | ($this->palette[$i][1] << 8) | $this->palette[$i][2]), -6);
                $result .= ' <span style="background:#' . $color . '">#</span>' . $color;
            }
            $result .= "\n";
        }
        if ($include & self::TOSTRING_CHARS) {
            $result .= 'chars: ';
            for ($i = 0; $i < 256; $i++) {
                $result .= ($this->tablo[$i]['shift'] ? ($i <= 32 ? "<small>#$i</small> " : ($i >= 127 ? " <small>#$i</small>" : chr($i))) : '');
            }
            $result .= "\n";        	
        }
        return $result;
    }
}
