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

    /** @var int used in imageText(), output as transparent PNG */
    const OUTPUT_PNG = 1;

    /** @var int used in imageText(), return image resource */
    const OUTPUT_RESOURCE = 2;

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
     * Open a font
     *
     * @return bool succes status
     */
    public function open()
    {
        if ($this->accessMode == self::ACCESS_FILE) {
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

    function __construct($font = null)
    {
        if (($this->font = $font) !== null) {
            $this->load($font);
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
        $this->sizeOver = unpack('C', $buffer[6])[1];
        $this->sizeUnder = unpack('C', $buffer[7])[1];
        $this->addSpace = unpack('C', $buffer[8])[1];
        $this->sizeInner = unpack('C', $buffer[9])[1];
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
                'relx' => unpack('C', $buffer[3])[1],
                'rely' => unpack('C', $buffer[4])[1],
                'shift' => unpack('C', $buffer[5])[1],
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
     * Output font's text as image
     *
     * @param string $text
     * @param int $output 1=send header to image/gif and output the image, 0=return image resource
     * @return void|resource|false according to $output
     */
    function imageText($text, $output = self::OUTPUT_PNG)
    {
        for ($i = 0, $width = 0; $i < strlen($text) - 1; $i++) { // determine image width
            $width += (isset($this->tablo[ord($text[$i])]['shift']) ? $this->tablo[ord($text[$i])]['shift'] : 0)
                + $this->addSpace;
        }
        $image = imagecreate($width + ($x = max(-$this->tablo[ord($text[0])]['relx'], 0)) 
            + $this->tablo[ord($text[strlen($text) - 1])]['relx'] 
            + $this->tablo[ord($text[strlen($text) - 1])]['width']
            + (strlen($text) - 1) * $this->letterGap, max($this->lineHeight, -$this->sizeOver + $this->sizeUnder));
        imagealphablending($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        foreach ($this->palette as $key => $value) {
            $pal[$key] = imagecolorallocate($image, $value[0], $value[1], $value[2]);
        }
        for ($i = 0; $i < strlen($text); $i++) {
            $letter = &$this->tablo[ord($text[$i])];
            for ($j = 0, $y = $letter['rely']; $j < $letter['height']; $j++) { 
                for ($k = 0; $k < $letter['width']; $k++) {
                   imagesetpixel($image, $x + $letter['relx'] + $k, $y + $j, 
                ord($letter['data'][$j * $letter['width'] + $k]));
                }
            }
            $x += $letter['shift'] + $this->addSpace + $this->letterGap;
        }
        if ($output == self::OUTPUT_PNG) {
            header('Content-type: image/png');
            imagepng($image);
            imagedestroy($image);
        } elseif ($output == self::OUTPUT_RESOURCE) {
            return $image;
        } else {
            imagedestroy($image);
            return false;
        }
    }
}
