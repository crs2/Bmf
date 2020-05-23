
# Bmf

`Bmf` is a PHP class to load and output Bytemap (`*.bmf`) fonts.
Bitmap fonts consist of bitmaps of its characters (as opposite to vector shapes in e.g. TTF/OTF fonts); so called bytemap fonts consist of bitmaps having more than one bit of information (i.e. more than two colors).
In 1980s and 90s a tons of neat "picture fonts" were created - mostly in games and demoscene. Those can be found in a form of images with all characters drawn in an array or a line one next to each other. The BMF file format takes and organizes those bitmaps in individual characters and makes it possible to actually use the font and write something with it.

## Resources
- [Bytemap Fonts](http://bmf.php5.cz/) | [BMF file format](http://bmf.php5.cz/?page=format)

## Examples

### Instatiate a class object
...and load given font from a file
```php
include 'Bmf.php';
$Bmf = new Bmf();
$Bmf->open('bulvar.bmf');
```
...or load the font right away with the creation of the object
```php
$Bmf = new Bmf('bulvar.bmf');
```

### Load a different font
To do that, you just call the `open()` method again.
```php
$Bmf->open('bent6.bmf');
```

### Load a font from memory
```php
$font_data = file_get_contents('bent7.bmf');
$Bmf = new Bmf($font_data, $Bmf::ACCESS_MEMORY);
```

### Output a PNG
This example produces a PNG image with "ABC" written in it with the current font.
```php
$Bmf->textAsImage('ABC');
```
- PNG is the default image format when outputting to images.
- The background (color attribute 0) is black and transparent by default.

To make the background opaque, set...
```php
$Bmf->transparentBackground = false;
```

### Output an image in HTML
Return HTML's `<img>` tag with base64-encoded content
```php
echo $Bmf->textAsImage('ABC', $Bmf::OUTPUT_HTML);
```

### Return the image resource
This example draws the font's own title using PHP's GD library and return the image resource
```php
$image = $Bmf->textAsImage($Bmf->title, $Bmf::OUTPUT_RESOURCE);
// let's say we then output it as JPEG
imagejpeg($image, 'output.jpg');
imagedestroy($image);
```

### Draw to an existing image
Draw with current font to an image created by `imagecreate()`.
```php
$image = imagecreate(300, 300);
$Bmf->imageString($image, 10, 10, 'ABC');
```

### Debug info
To print properties of current font in human-readable form.
```php
echo $Bmf->toString();
```
