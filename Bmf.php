<?php
/**
 * This class can load a BMF file and output its character bitmaps.
 *
 * @author crs@centrum.cz
 */
class Bmf {

    /** @var constant in the beginning of the file */
    const MAGIC_HEADER = "\xE1\xE6\xD5\x1A";

    /** @var int access as file */
    const ACCESS_FILE = 0;

    /** @var int access as memory/string */
    const ACCESS_MEMORY = 1;

    /** @var int used in textAsImage(), output as transparent PNG */
    const OUTPUT_PNG = 1;

    /** @var int used in textAsImage(), output as transparent HTML <img src="data:..."> */
    const OUTPUT_HTML = 2;

    /** @var int used in textAsImage(), return image resource */
    const OUTPUT_RESOURCE = 3;

    /** @var bool used in toString(), list palette */
    const TOSTRING_PALETTE = true;

    /** @var bool used in toString(), list chars */
    const TOSTRING_CHARS = true;

    /** @var int way to access the module */
    public $accessMode = self::ACCESS_FILE;

    /** @var resource|null file handle for reading from disk */
    public $fileHandle;

    /** @var int offset for reading from memory */
    public $offset = 0;

    /** @var string font's file name or memory */
    public $font;

    /** @var int version (0x11 for v1.1) */
    public $version;

    /** @var int line height */
    public $lineHeight;

    /** @var int length over the base line in pixels (–128…127) */
    public $sizeOver;

    /** @var int length under the base line in pixels (–128…127) */
    public $sizeUnder;

    /** @var int add space after each char in pixels (–128…127) */
    public $addSpace;

    /** @var int size inner (small letters height) (–128…127) */
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

    /** @var int extra gap between letters when outputting text */
    public $letterGap = 0;

    /**
     * Constructor. Load a font if parameter provided.
     *
     * @param
     */
    function __construct($font = null)
    {
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
     * Closes a font
     *
     * @return bool succes status
     */
    public function close()
    {
        if ($this->accessMode == self::ACCESS_FILE) {
            fclose($this->fileHandle);
            $this->fileHandle = null;
        }
    }

    /**
     * Allocate font's palette to given image
     *
     * @param resource &$image
     * @return void
     */
    public function imagePalette(&$image)
    {
        foreach ($this->palette as $key => $value) {
            $pal[$key] = imagecolorallocate($image, $value[0], $value[1], $value[2]);
        }
    }

    /**
     * Output string to given image.
     *
     * @param resource &$image
     * @param int $x x-coordinate
     * @param int $y y-coordinate
     * @param string $text text to output
     * @return int width of the text
     */
    public function imageString(&$image, $x, $y, $text)
    {
        for ($i = 0, $x0 = $x; $i < strlen($text); $i++) {
            $letter = &$this->tablo[ord($text[$i])];
            for ($j = 0, $rely = $letter['rely']; $j < $letter['height']; $j++) { 
                for ($k = 0; $k < $letter['width']; $k++) {
                    imagesetpixel($image, $x + $letter['relx'] + $k, $y + $rely + $j, 
                        ord($letter['data'][$j * $letter['width'] + $k]));
                }
            }
            $x += $letter['shift'] + $this->addSpace + $this->letterGap;
        }
    }

    /**
     * Load a BMF font
     *
     * @param string $filename font's file name or memory
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
                'shift' => unpack('c', $buffer[5])[1],
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
     * Open a font
     *
     * @return bool succes status
     */
    public function open()
    {
        if ($this->accessMode == self::ACCESS_FILE) {
            if (!file_exists($this->font)) {
                return false;
            }
            return $this->fileHandle = fopen($this->font, 'r');
        }
    }

    /**
     * Read next $bytes bytes off a font.
     *
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
     * Output font's text as image
     *
     * @param string $text
     * @param int $output see self::OUTPUT_* constants
     * @return void|resource|false according to $output
     */
    public function textAsImage($text, $output = self::OUTPUT_PNG)
    {
        for ($i = 0, $width = 0; $i < strlen($text) - 1; $i++) { // determine image width
            $width += $this->tablo[ord($text[$i])]['shift'] + $this->addSpace;
        }
        $image = imagecreate($width + ($x = max(-$this->tablo[ord($text[0])]['relx'], 0)) 
            + $this->tablo[ord($text[strlen($text) - 1])]['relx'] 
            + $this->tablo[ord($text[strlen($text) - 1])]['width']
            + (strlen($text) - 1) * $this->letterGap, 
            $height = max($this->lineHeight, -$this->sizeOver + $this->sizeUnder));
        imagealphablending($image, true);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocatealpha($image, 0, 0, 0, 127));
        $this->imagePalette($image);
        $this->imageString($image, $x, 0, $text);
        switch ($output) {
            case self::OUTPUT_PNG:
                header('Content-type: image/png');
                imagepng($image);
                imagedestroy($image);
                exit;
            case self::OUTPUT_HTML:
                ob_start();
                imagepng($image);
                $image_data = ob_get_contents();
                imagedestroy($image);
                ob_end_clean(); 
                $result = '<img src="data:image/png;base64,' . base64_encode($image_data) . '" alt="' . htmlspecialchars($this->toString(), ENT_HTML5, 'UTF-8') . '"/>';
                return $result;
            case self::OUTPUT_RESOURCE:
                return $image;
            default:
                imagedestroy($image);
                return false;
        }
    }

    /**
     * Return font's parameters
     *
     * @return string
     */
    public function toString()
    {
        $result = '';
        foreach (['title', 'colors', 'numChars', 'lineHeight', 'sizeOver', 'sizeUnder', 'addSpace', 'sizeInner', 
            'usedColors', 'highestColor'] as $i) {
            $result .= "$i: " . $this->{$i} . "\n";
        }
        if (self::TOSTRING_PALETTE) {
            $result .= 'palette:';
            for ($i = 0; $i < $this->colors; $i++) {
                $result .= ' <span style="background:#' . ($color = substr('00000' . dechex(($this->palette[$i][0] << 16) | ($this->palette[$i][1] << 8) | $this->palette[$i][2]), -6)) . '">#</span>' . $color;
            }
            $result .= "\n";
        }
        if (self::TOSTRING_CHARS) {
            $result .= 'chars: ';
            for ($i = 0; $i < 256; $i++) {
                $result .= ($this->tablo[$i]['shift'] ? ($i <= 32 ? "#$i " : ($i > 127 ? " #$i" : chr($i))) : '');
            }
            $result .= "\n";        	
        }
        return $result;
    }
}
