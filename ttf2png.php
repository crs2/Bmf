<?php
/**
 * This simple script displays a charset table as a picture using TTF/OTF font
 * that is provided as a GET variable. Parameters:
 * font - TTF/OTF font file (without extention), mandatory
 * charw - character width (in pixels, default 50)
 * charh - character height (default charw * 1.6)
 * sizefactor - relative character size, default 0.9
 */
$fontfile = file_exists(($fontfile = $_GET['font'] ?? '') . '.ttf') ? "$fontfile.ttf" : "$fontfile.otf";
if (!file_exists($fontfile)) {
	die('Specify an existing TTF/OTF filename as the ?font= argument.');
}
$ascii = ' !"#$%&\'()*+,-./'
    . '0123456789:;<=>?'
    . '@ABCDEFGHIJKLMNO'
    . 'PQRSTUVWXYZ[\]^_'
    . '`abcdefghijklmno'
    . 'pqrstuvwxyz{|}~Δ'
    . 'ČüéďäĎŤčěĚĹÍÏĺÄÁ'
    . 'ÉžŽôöÓůÚýÖÜŠ£ÝŘť'
    . 'áíóúňŇŮºšř¬½¼¡«»'
    . '░▒▓│┤╡╢╖╕╣║╗╝╜╛┐'
    . '└┴┬├─┼╞╟╚╔╩╦╠═╬╧'
    . '╨╤╥╙╘╒╓╫╪┘┌█▄▌▐▀'
    . 'αβΓπΣδμγΦΘΩδ∞⧞∈∩'
    . '≡±≥≤⌠⌡÷≈°∙·√ⁿ²■ ';
$charW = $_GET['charw'] ?? 50;
$charH = $_GET['charh'] ?? (int)($charW * 1.6);
$sizeFactor = $_GET['sizefactor'] ?? 0.9;
$image = imagecreate($charW * 16, $charH * mb_strlen($ascii) / 16);
imagecolorallocate($image, 0, 0, 0);
$white = imagecolorallocate($image, 255, 255, 255);
for ($i = 0; $i < mb_strlen($ascii); $i++) {
    imagettftext($image, /*size*/min($charW, $charH) * $sizeFactor, /*angle*/0, 
        /*x*/(($i & 15) + 0.1) * $charW, /*y*/(($i >> 4) + 0.9) * $charH, 
        /*color*/$white, $fontfile, mb_substr($ascii, $i, 1));
}
header('Content-type: image/png;filename=' . $_GET['font'] . '.png');
imagepng($image);
