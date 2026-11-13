<?php

namespace App\Services\Studio;

/**
 * Exportacion a PowerPoint (PPTX, OOXML) del estudio: UNA diapositiva por pagina con la imagen PNG que renderizo Chromium, a pantalla
 * completa. Decision: el texto no es editable. El render del estudio (capas, grupos, tipografias propias, graficos, efectos) no tiene
 * equivalente fiel en las formas de PowerPoint; convertirlo capa a capa daria un resultado que no se parece al diseno. Quien necesite editar
 * el texto lo edita en el estudio y vuelve a exportar. El paquete se escribe sin `ext-zip` (`StudioZipWriter`) y sin dependencias nuevas.
 *
 * Tamano de diapositiva: 1 px = 9525 EMU (96 ppp), reducido de forma uniforme si el diseno superaria el maximo de PowerPoint (56 pulgadas).
 */
final class StudioPptxExporter
{
    public const EMU_PER_PIXEL = 9525;

    /** Margen bajo las 56 pulgadas (51.206.400 EMU) que admite PowerPoint por lado. */
    private const MAX_EMU = 50_000_000;

    private const MIN_EMU = 914_400;

    private const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_P = 'http://schemas.openxmlformats.org/presentationml/2006/main';

    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * @param  list<array{path: string, name: string}>  $slides  imagenes PNG en el orden de las paginas
     */
    public function build(string $target, array $slides, int $width, int $height, string $title): void
    {
        if ($slides === [] || $width < 1 || $height < 1) {
            throw new StudioRenderFailure('No hay páginas que empaquetar en la presentación.');
        }
        [$cx, $cy] = $this->slideSize($width, $height);
        $zip = new StudioZipWriter($target);
        try {
            $zip->addString('[Content_Types].xml', $this->contentTypes(count($slides)));
            $zip->addString('_rels/.rels', $this->rels([
                ['rId1', 'officeDocument', 'ppt/presentation.xml'],
                ['rId2', 'package/2006/relationships/metadata/core-properties', 'docProps/core.xml'],
            ]));
            $zip->addString('docProps/core.xml', $this->core($title));
            $zip->addString('ppt/presentation.xml', $this->presentation(count($slides), $cx, $cy));
            $zip->addString('ppt/_rels/presentation.xml.rels', $this->rels(array_merge(
                [['rId1', 'slideMaster', 'slideMasters/slideMaster1.xml'], ['rId2', 'theme', 'theme/theme1.xml']],
                array_map(fn (int $i) => ['rId'.($i + 3), 'slide', 'slides/slide'.($i + 1).'.xml'], array_keys($slides)),
            )));
            $zip->addString('ppt/slideMasters/slideMaster1.xml', $this->master());
            $zip->addString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $this->rels([['rId1', 'slideLayout', '../slideLayouts/slideLayout1.xml'], ['rId2', 'theme', '../theme/theme1.xml']]));
            $zip->addString('ppt/slideLayouts/slideLayout1.xml', $this->layout());
            $zip->addString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $this->rels([['rId1', 'slideMaster', '../slideMasters/slideMaster1.xml']]));
            $zip->addString('ppt/theme/theme1.xml', $this->theme());
            foreach ($slides as $i => $slide) {
                $n = $i + 1;
                $zip->addString("ppt/slides/slide$n.xml", $this->slide($n, $slide['name'], $cx, $cy));
                $zip->addString("ppt/slides/_rels/slide$n.xml.rels", $this->rels([['rId1', 'slideLayout', '../slideLayouts/slideLayout1.xml'], ['rId2', 'image', "../media/image$n.png"]]));
                $zip->addFile("ppt/media/image$n.png", $slide['path']);
            }
        } finally {
            $zip->close();
        }
    }

    /** @return array{0: int, 1: int} */
    private function slideSize(int $width, int $height): array
    {
        $cx = $width * self::EMU_PER_PIXEL;
        $cy = $height * self::EMU_PER_PIXEL;
        // Escala uniforme (la imagen no se deforma): hacia abajo si pasa del maximo de PowerPoint, hacia arriba si no llega a 1 pulgada.
        $k = min(1, self::MAX_EMU / max($cx, $cy));
        if (min($cx, $cy) * $k < self::MIN_EMU) {
            $k = min(self::MAX_EMU / max($cx, $cy), self::MIN_EMU / min($cx, $cy));
        }

        return [(int) round($cx * $k), (int) round($cy * $k)];
    }

    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function xml(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".$body;
    }

    private function contentTypes(int $slides): string
    {
        $parts = [['/ppt/presentation.xml', 'presentationml.presentation.main'], ['/ppt/slideMasters/slideMaster1.xml', 'presentationml.slideMaster'], ['/ppt/slideLayouts/slideLayout1.xml', 'presentationml.slideLayout'], ['/ppt/theme/theme1.xml', 'theme']];
        $xml = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/>';
        foreach ($parts as [$name, $type]) {
            $xml .= '<Override PartName="'.$name.'" ContentType="application/vnd.openxmlformats-officedocument.'.($type === 'theme' ? 'theme+xml' : $type.'+xml').'"/>';
        }
        for ($n = 1; $n <= $slides; $n++) {
            $xml .= '<Override PartName="/ppt/slides/slide'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
        }

        return $this->xml($xml.'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>');
    }

    /** @param list<array{0: string, 1: string, 2: string}> $relations [id, tipo (relativo a officeDocument/2006/relationships), destino] */
    private function rels(array $relations): string
    {
        $xml = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($relations as [$id, $type, $target]) {
            $xml .= '<Relationship Id="'.$id.'" Type="'.(str_starts_with($type, 'package/') ? 'http://schemas.openxmlformats.org/'.$type : self::REL.'/'.$type).'" Target="'.$this->esc($target).'"/>';
        }

        return $this->xml($xml.'</Relationships>');
    }

    private function core(string $title): string
    {
        return $this->xml('<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>'.$this->esc($title).'</dc:title><dc:creator>Estudio</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created></cp:coreProperties>');
    }

    private function presentation(int $slides, int $cx, int $cy): string
    {
        $ids = '';
        for ($n = 1; $n <= $slides; $n++) {
            $ids .= '<p:sldId id="'.(255 + $n).'" r:id="rId'.($n + 2).'"/>';
        }

        return $this->xml('<p:presentation xmlns:a="'.self::NS_A.'" xmlns:r="'.self::NS_R.'" xmlns:p="'.self::NS_P.'"><p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst><p:sldIdLst>'.$ids.'</p:sldIdLst><p:sldSz cx="'.$cx.'" cy="'.$cy.'"/><p:notesSz cx="6858000" cy="9144000"/></p:presentation>');
    }

    private function emptyTree(): string
    {
        return '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>';
    }

    private function master(): string
    {
        return $this->xml('<p:sldMaster xmlns:a="'.self::NS_A.'" xmlns:r="'.self::NS_R.'" xmlns:p="'.self::NS_P.'"><p:cSld><p:bg><p:bgRef idx="1001"><a:schemeClr val="bg1"/></p:bgRef></p:bg>'.$this->emptyTree().'</p:spTree></p:cSld><p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/><p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst></p:sldMaster>');
    }

    private function layout(): string
    {
        return $this->xml('<p:sldLayout xmlns:a="'.self::NS_A.'" xmlns:r="'.self::NS_R.'" xmlns:p="'.self::NS_P.'" type="blank" preserve="1"><p:cSld name="En blanco">'.$this->emptyTree().'</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>');
    }

    private function slide(int $n, string $name, int $cx, int $cy): string
    {
        $label = $this->esc(mb_substr($name !== '' ? $name : 'Página '.$n, 0, 120));

        return $this->xml('<p:sld xmlns:a="'.self::NS_A.'" xmlns:r="'.self::NS_R.'" xmlns:p="'.self::NS_P.'"><p:cSld name="'.$label.'">'.$this->emptyTree()
            .'<p:pic><p:nvPicPr><p:cNvPr id="2" name="Página '.$n.'" descr="'.$label.'"/><p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr><p:blipFill><a:blip r:embed="rId2"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
            .'<p:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$cx.'" cy="'.$cy.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic></p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>');
    }

    private function theme(): string
    {
        $colors = ['dk2' => '44546A', 'lt2' => 'E7E6E6', 'accent1' => '4472C4', 'accent2' => 'ED7D31', 'accent3' => 'A5A5A5', 'accent4' => 'FFC000', 'accent5' => '5B9BD5', 'accent6' => '70AD47', 'hlink' => '0563C1', 'folHlink' => '954F72'];
        $scheme = '<a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1><a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1>';
        foreach ($colors as $tag => $value) {
            $scheme .= "<a:$tag><a:srgbClr val=\"$value\"/></a:$tag>";
        }
        $font = '<a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/>';
        $fill = str_repeat('<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>', 3);

        return $this->xml('<a:theme xmlns:a="'.self::NS_A.'" name="Estudio"><a:themeElements><a:clrScheme name="Estudio">'.$scheme.'</a:clrScheme>'
            .'<a:fontScheme name="Estudio"><a:majorFont>'.$font.'</a:majorFont><a:minorFont>'.$font.'</a:minorFont></a:fontScheme>'
            .'<a:fmtScheme name="Estudio"><a:fillStyleLst>'.$fill.'</a:fillStyleLst><a:lnStyleLst>'.str_repeat('<a:ln w="6350"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>', 3).'</a:lnStyleLst>'
            .'<a:effectStyleLst>'.str_repeat('<a:effectStyle><a:effectLst/></a:effectStyle>', 3).'</a:effectStyleLst><a:bgFillStyleLst>'.$fill.'</a:bgFillStyleLst></a:fmtScheme></a:themeElements></a:theme>');
    }
}
