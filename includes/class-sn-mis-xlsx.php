<?php
if (!defined('ABSPATH')) { exit; }

/** Bounded-memory XLSX writer. Every cell is a string, so formula input stays inert. */
final class SN_MIS_XLSX
{
    private string $sheet;
    private $stream;
    private int $row = 0;
    private bool $keep=false;
    public function __construct(array $headers, ?string $sheet=null, ?array $checkpoint=null) {
        if (!class_exists('ZipArchive')) { throw new RuntimeException('افزونه PHP ZIP برای خروجی Excel لازم است.'); }
        $this->keep=$sheet!==null;
        $this->sheet=$sheet??wp_tempnam('mis-sheet');
        if (!$this->sheet) { throw new RuntimeException('فایل موقت خروجی ساخته نشد.'); }
        $this->stream=fopen($this->sheet,$checkpoint?'c+b':'wb');
        if (!$this->stream) { throw new RuntimeException('فایل موقت خروجی باز نشد.'); }
        @chmod($this->sheet,0600);
        if ($checkpoint) {
            $offset=(int)$checkpoint['offset'];clearstatcache(true,$this->sheet); if (filesize($this->sheet)<$offset || !ftruncate($this->stream,$offset) || fseek($this->stream,$offset)!==0) { throw new RuntimeException('نقطه ادامه خروجی معتبر نیست.'); }
            $this->row=(int)$checkpoint['rows'];return;
        }
        $this->write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0" rightToLeft="1"/></sheetViews><sheetData>');
        $this->add($headers);
    }
    private function write(string $text): void {
        $remaining=$text;
        while ($remaining!=='') { $n=fwrite($this->stream,$remaining); if ($n===false || $n===0) { throw new RuntimeException('نوشتن خروجی کامل نشد.'); } $remaining=substr($remaining,$n); }
    }
    public function add(array $values): void {
        $this->row++; if ($this->row>1048576) { throw new RuntimeException('تعداد نتایج از سقف یک برگه Excel بیشتر است؛ بازه گزارش را کوچک‌تر کنید.'); }
        $this->write('<row r="'.$this->row.'">');
        foreach(array_values($values) as $i=>$value) {
            $column=''; $n=$i+1; while($n>0) { $n--; $column=chr(65+$n%26).$column; $n=intdiv($n,26); }
            $value=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/','',(string)$value);
            $this->write('<c r="'.$column.$this->row.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8').'</t></is></c>');
        }
        $this->write('</row>');
    }
    public function checkpoint(): array {
        if (!fflush($this->stream)) { throw new RuntimeException('ذخیره نقطه ادامه خروجی ممکن نشد.'); }
        if (function_exists('fsync')) { fsync($this->stream); }
        return ['offset'=>ftell($this->stream),'rows'=>$this->row];
    }
    public function finish(?string $destination=null): string {
        $this->write('</sheetData></worksheet>'); fclose($this->stream); $this->stream=null;
        $path=$destination??wp_tempnam('mis-report.xlsx'); $zip=new ZipArchive();
        if (!$path || $zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) { throw new RuntimeException('بسته خروجی ساخته نشد.'); }
        $parts=[
            '[Content_Types].xml'=>'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml'=>'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="MIS" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>'
        ];
        foreach($parts as $name=>$xml) { if (!$zip->addFromString($name,$xml)) { $zip->close(); @unlink($path); throw new RuntimeException('ساخت خروجی کامل نشد.'); } }
        if (!$zip->addFile($this->sheet,'xl/worksheets/sheet1.xml') || !$zip->close()) { @unlink($path); throw new RuntimeException('بسته‌بندی خروجی کامل نشد.'); }
        @chmod($path,0600); if (!$this->keep) { unlink($this->sheet); $this->sheet=''; } return $path;
    }
    public function __destruct() { if (is_resource($this->stream)) { fclose($this->stream); } if (!$this->keep && $this->sheet!=='' && is_file($this->sheet)) { unlink($this->sheet); } }
}
