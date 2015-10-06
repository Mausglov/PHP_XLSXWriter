<?php

/**
 * @license MIT License
 * https://github.com/mk-j/PHP_XLSXWriter
 *
 */

class xlsxWriter
{
    //------------------------------------------------------------------
    protected $author = 'Doc Author';
    /**
     * @var XLSXWriter_Sheet[]
     */
    protected $sheets = array();
    /**
     * уникальный набор на документ
     * unique set
     *
     * @var array
     */
    protected $shared_strings = array();

    protected $shared_string_count = 0;//count of non-unique references to the unique set
    protected $temp_files = array();
    protected $cell_formats = array();//contains excel format like YYYY-MM-DD HH:MM:SS
    /**
     * contains friendly format like datetime
     * @TODO непонятно, этот набор как-то привязан к документу или к листу?
     *
     * @var array
     */
    protected $cell_types = array();//

    protected $current_sheet = '';

    public function __construct()
    {
        if (!class_exists('ZipArchive')) { throw new Exception('ZipArchive not found'); }
        
        if (!ini_get('date.timezone')) {
            //using date functions can kick out warning if this isn't set
            date_default_timezone_set('UTC');
        }

        $this->addCellFormat($cell_format = 'GENERAL');
    }

    public function setAuthor($author = '')
    {
        $this->author = $author;
    }

    public function __destruct()
    {
        if (!empty($this->temp_files)) {
            foreach ($this->temp_files as $temp_file) {
                @unlink($temp_file);
            }
        }
    }

    /**
     * @param $index
     * @return string
     */
    public function getCellTypeByIndex( $index )
    {
        if ( !array_key_exists( $index, $this->cell_types) ) {
            $this->cell_types[$index] = self::determineCellType( '' );
        }
        return $this->cell_types[$index];
    }

    protected function tempFilename()
    {
        $filename = tempnam(sys_get_temp_dir(), "xlsx_writer_");
        $this->temp_files[] = $filename;
        return $filename;
    }

    public function writeToStdOut()
    {
        $temp_file = $this->tempFilename();
        self::writeToFile($temp_file);
        readfile($temp_file);
    }

    public function writeToString()
    {
        $temp_file = $this->tempFilename();
        self::writeToFile($temp_file);
        $string = file_get_contents($temp_file);
        return $string;
    }

    public function writeToFile($filename)
    {
        if (!class_exists('ZipArchive')) {
            throw new Exception('ZipArchive not found');
        }

        foreach ($this->sheets as $sheet_name => $sheet) {
            self::finalizeSheet($sheet_name);//making sure all footers have been written
        }

        if (file_exists($filename)) {
            if (is_writable($filename)) {
                @unlink($filename); //if the zip already exists, remove it
            } else {
                self::log("Error in " . __CLASS__ . "::" . __FUNCTION__ . ", file is not writeable.");
                return;
            }
        }
        $zip = new ZipArchive();
        if (empty($this->sheets)) {
            self::log("Error in " . __CLASS__ . "::" . __FUNCTION__ . ", no worksheets defined.");
            return;
        }
        if (!$zip->open($filename, ZipArchive::CREATE)) {
            self::log("Error in " . __CLASS__ . "::" . __FUNCTION__ . ", unable to create zip.");
            return;
        }

        $zip->addEmptyDir("docProps/");
        $zip->addFromString("docProps/app.xml", self::buildAppXML());
        $zip->addFromString("docProps/core.xml", self::buildCoreXML());

        $zip->addEmptyDir("_rels/");
        $zip->addFromString("_rels/.rels", self::buildRelationshipsXML());

        $zip->addEmptyDir("xl/worksheets/");
        if ( !empty( $this->relations ) ) {
            $zip->addEmptyDir("xl/worksheets/_rels/");
        }
        foreach ($this->sheets as $sheetName => $sheet) {
            $zip->addFile($sheet->filename, "xl/worksheets/" . $sheet->xmlname);
            if ( $sheet->hasRelations() ) {
                $zip->addFromString( "xl/worksheets/_rels/" . $sheet->xmlname.'.rels' , $sheet->buildRelationshipXML() );
            }
        }
        if (!empty($this->shared_strings)) {
            $zip->addFile(
                $this->writeSharedStringsXML(),
                "xl/sharedStrings.xml"
            );  //$zip->addFromString("xl/sharedStrings.xml",     self::buildSharedStringsXML() );
        }
        $zip->addFromString("xl/workbook.xml", self::buildWorkbookXML());
        $zip->addFile(
            $this->writeStylesXML(),
            "xl/styles.xml"
        );  //$zip->addFromString("xl/styles.xml"           , self::buildStylesXML() );
        $zip->addFromString("[Content_Types].xml", self::buildContentTypesXML());

        $zip->addEmptyDir("xl/_rels/");
        $zip->addFromString("xl/_rels/workbook.xml.rels", self::buildWorkbookRelsXML());
        $zip->close();
    }

    public function getSheet( $name )
    {
        if ( array_key_exists($name, $this->sheets) ) {
            return $this->sheets[ $name ];
        }
        throw new InvalidArgumentException('wrong $name: ' . var_export($name, 1));
    }

    protected function initializeSheet($sheet_name)
    {
        //if already initialized
        if ($this->current_sheet == $sheet_name || isset($this->sheets[$sheet_name])) {
            return;
        }

        $sheet_filename = $this->tempFilename();
        $sheet_xmlname = 'sheet' . (count($this->sheets) + 1) . ".xml";
        $sheet = new XLSXWriter_Sheet( $this, $sheet_filename, $sheet_name, $sheet_xmlname);

        $this->sheets[$sheet_name] = $sheet;
        $sheet->writeProlog( count($this->sheets) );
    }

    private static function determineCellType($cell_format)
    {
        $cell_format = str_replace("[RED]", "", $cell_format);
        if ($cell_format == 'GENERAL') {
            return 'string';
        }
        if ($cell_format == '0') {
            return 'numeric';
        }
        if (preg_match("/[H]{1,2}:[M]{1,2}/", $cell_format)) {
            return 'datetime';
        }
        if (preg_match("/[M]{1,2}:[S]{1,2}/", $cell_format)) {
            return 'datetime';
        }
        if (preg_match("/[YY]{2,4}/", $cell_format)) {
            return 'date';
        }
        if (preg_match("/[D]{1,2}/", $cell_format)) {
            return 'date';
        }
        if (preg_match("/[M]{1,2}/", $cell_format)) {
            return 'date';
        }
        if (preg_match("/$/", $cell_format)) {
            return 'currency';
        }
        if (preg_match("/%/", $cell_format)) {
            return 'percent';
        }
        if (preg_match("/0/", $cell_format)) {
            return 'numeric';
        }
        return 'string';
    }

    private static function escapeCellFormat($cell_format)
    {
        $ignore_until = '';
        $escaped = '';
        for ($i = 0, $ix = strlen($cell_format); $i < $ix; $i++) {
            $c = $cell_format[$i];
            if ($ignore_until == '' && $c == '[') {
                $ignore_until = ']';
            } else {
                if ($ignore_until == '' && $c == '"') {
                    $ignore_until = '"';
                } else {
                    if ($ignore_until == $c) {
                        $ignore_until = '';
                    }
                }
            }
            if ($ignore_until == '' && ($c == ' ' || $c == '-' || $c == '(' || $c == ')') && ($i == 0 || $cell_format[$i - 1] != '_')) {
                $escaped .= "\\" . $c;
            } else {
                $escaped .= $c;
            }
        }
        return $escaped;
        //return str_replace( array(" ","-", "(", ")"), array("\ ","\-", "\(", "\)"), $cell_format);//TODO, needs more escaping
    }

    private function addCellFormat($cell_format)
    {
        //for backwards compatibility, to handle older versions

        switch ( $cell_format ) {
            case 'string':
                $cell_format = 'GENERAL';
                break;

            case 'integer':
                $cell_format = '0';
                break;

            case 'date':
                $cell_format = 'YYYY-MM-DD';
                break;

            case 'datetime':
                $cell_format = 'YYYY-MM-DD HH:MM:SS';
                break;

            case 'dollar':
            case 'money':
                $cell_format = '[$$-1009]#,##0.00;[RED]-[$$-1009]#,##0.00';
                break;

            case 'euro':
                $cell_format = '#,##0.00 [$€-407];[RED]-#,##0.00 [$€-407]';
                break;

            case 'NNNN':
                $cell_format = 'DDDD", "';
                break;

            case 'NNN':
                $cell_format = 'DDDD';
                break;

            case 'NN':
                $cell_format = 'DDD';
                break;
        }

        $cell_format = strtoupper($cell_format);
        $position = array_search($cell_format, $this->cell_formats, $strict = true);
        if ($position === false) {
            $position = count($this->cell_formats);
            $this->cell_formats[] = self::escapeCellFormat($cell_format);
            $this->cell_types[] = self::determineCellType($cell_format);
        }
        return $position;
    }

    public function writeSheetHeader($sheet_name, array $header_types, $suppress_row = false)
    {
        if (empty($sheet_name) || empty($header_types) || !empty($this->sheets[$sheet_name])) {
            return;
        }

        self::initializeSheet($sheet_name);
        $sheet = $this->getSheet($sheet_name);
        $sheet->columns = array();
        foreach ($header_types as $v) {
            $sheet->columns[] = $this->addCellFormat($v);
        }
        if (!$suppress_row) {
            $header_row = array_keys($header_types);

            $sheet->file_writer->write(
                '<row collapsed="false" customFormat="false" customHeight="false" hidden="false" ht="12.1" outlineLevel="0" r="' . (1) . '">'
            );
            foreach ($header_row as $k => $v) {
                $sheet->writeCell($sheet->file_writer, 0, $k, $v, $cell_format_index = '0');//'0'=>'string'
            }
            $sheet->file_writer->write('</row>');
            $sheet->row_count++;
        }
        $this->current_sheet = $sheet_name;
    }

    public function writeSheetRow($sheet_name, array $row, $formatMap = array())
    {
        if (empty($sheet_name) || empty($row)) {
            return;
        }

        self::initializeSheet($sheet_name);
        $sheet = $this->sheets[$sheet_name];
        $quantity = count($row); // число ячеек в этой строке
        if (empty($sheet->columns)) {
            $sheet->columns = array_fill(0, $quantity, '0');//'0'=>'string'
        } else {
            if ( $quantity > count($sheet->columns) ) {
                $sheet->columns = array_pad($sheet->columns, $quantity, '0');
            }
        }

        if ( is_array( $formatMap ) && count( $formatMap ) ) {
            foreach( $formatMap as $format ) {
                if ( !in_array( $format, $this->cell_formats ) ) {
                    $this->addCellFormat( $format );
                }
            }
        }



        $sheet->file_writer->write(
            '<row collapsed="false" customFormat="false" customHeight="false" hidden="false" ht="12.1" outlineLevel="0" r="' . ($sheet->row_count + 1) . '">'
        );
        $column_count = 0;
        foreach ($row as $k => $v) {
            $sheet->writeCell($sheet->file_writer, $sheet->row_count, $column_count, $v, $sheet->columns[$column_count]);
            $column_count++;
        }
        $sheet->file_writer->write('</row>');
        $sheet->row_count++;
        $this->current_sheet = $sheet_name;
    }

    protected function finalizeSheet($sheet_name)
    {
        if (empty($sheet_name) || !array_key_exists($sheet_name, $this->sheets)) {
            return;
        }

        $sheet = $this->sheets[$sheet_name];

        $sheet->finalizeSheet();

    }

    public function markMergedCell($sheet_name, $start_cell_row, $start_cell_column, $end_cell_row, $end_cell_column)
    {
        if (empty($sheet_name) || $this->sheets[$sheet_name]->finalized) {
            return;
        }

        self::initializeSheet($sheet_name);
        $sheet = $this->sheets[$sheet_name];

        $startCell = XLSXWriter_Sheet::xlsCell($start_cell_row, $start_cell_column);
        $endCell = XLSXWriter_Sheet::xlsCell($end_cell_row, $end_cell_column);
        $sheet->merge_cells[] = $startCell . ":" . $endCell;
    }

    public function writeSheet(array $data, $sheet_name = '', array $header_types = array())
    {
        $sheet_name = empty($sheet_name) ? 'Sheet1' : $sheet_name;
        $data = empty($data) ? array(array('')) : $data;
        if (!empty($header_types)) {
            $this->writeSheetHeader($sheet_name, $header_types);
        }
        foreach ($data as $i => $row) {
            $this->writeSheetRow($sheet_name, $row);
        }
        $this->finalizeSheet($sheet_name);
    }

    protected function writeStylesXML()
    {
        $temporary_filename = $this->tempFilename();
        $file = new XLSXWriter_BuffererWriter($temporary_filename);
        $file->write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n");
        $file->write('<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">');
        $file->write('<numFmts count="' . count($this->cell_formats) . '">');
        foreach ($this->cell_formats as $i => $v) {
            $file->write('<numFmt numFmtId="' . (164 + $i) . '" formatCode="' . self::xmlspecialchars($v) . '" />');
        }
        //$file->write(		'<numFmt formatCode="GENERAL" numFmtId="164"/>');
        //$file->write(		'<numFmt formatCode="[$$-1009]#,##0.00;[RED]\-[$$-1009]#,##0.00" numFmtId="165"/>');
        //$file->write(		'<numFmt formatCode="YYYY-MM-DD\ HH:MM:SS" numFmtId="166"/>');
        //$file->write(		'<numFmt formatCode="YYYY-MM-DD" numFmtId="167"/>');
        $file->write('</numFmts>');
        $file->write('<fonts count="4">');
        $file->write('<font><name val="Arial"/><charset val="1"/><family val="2"/><sz val="10"/></font>');
        $file->write('<font><name val="Arial"/><family val="0"/><sz val="10"/></font>');
        $file->write('<font><name val="Arial"/><family val="0"/><sz val="10"/></font>');
        $file->write('<font><name val="Arial"/><family val="0"/><sz val="10"/></font>');
        $file->write('</fonts>');
        $file->write(
            '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        );
        $file->write(
            '<borders count="1"><border diagonalDown="false" diagonalUp="false"><left/><right/><top/><bottom/><diagonal/></border></borders>'
        );
        $file->write('<cellStyleXfs count="20">');
        $file->write(
            '<xf applyAlignment="true" applyBorder="true" applyFont="true" applyProtection="true" borderId="0" fillId="0" fontId="0" numFmtId="164">'
        );
        $file->write(
            '<alignment horizontal="general" indent="0" shrinkToFit="false" textRotation="0" vertical="bottom" wrapText="false"/>'
        );
        $file->write('<protection hidden="false" locked="true"/>');
        $file->write('</xf>');
        $file->write(
            '<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="1" numFmtId="0"/>'
        );
        $file->write(
            '<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="1" numFmtId="0"/>'
        );
        $file->write(
            '<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="2" numFmtId="0"/>'
        );
        $file->write(
            '<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="2" numFmtId="0"/>'
        );
        $file->write(
            '<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="0"/>'
        );
        $file->write(
            '<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="0"/>'
        );
        $file->write(
            '<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="0"/>'
        );
        $file->write(
            '<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="0"/>'
        );
        $file->write(
            '<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="0"/>'
        );
        $file->write(
            '<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="0"/>'
        );
        $file->write(
            '<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="0"/>'
        );
        $file->write(
            '<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="0"/>'
        );
        $file->write(
            '<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="0"/>'
        );
        $file->write(
            '<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="0"/>'
        );
        $file->write(
            '<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="1" numFmtId="43"/>'
        );
        $file->write(
            '<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="1" numFmtId="41"/>'
        );
        $file->write(
            '<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="1" numFmtId="44"/>'
        );
        $file->write(
            '<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="1" numFmtId="42"/>'
        );
        $file->write(
            '<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="1" numFmtId="9"/>'
        );
        $file->write('</cellStyleXfs>');

        $file->write('<cellXfs count="' . count($this->cell_formats) . '">');
        foreach ($this->cell_formats as $i => $v) {
            $file->write(
                '<xf applyAlignment="false" applyBorder="false" applyFont="false" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="' . (164 + $i) . '" xfId="0"/>'
            );
        }
        $file->write('</cellXfs>');
        //$file->write(	'<cellXfs count="4">');
        //$file->write(		'<xf applyAlignment="false" applyBorder="false" applyFont="false" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="164" xfId="0"/>');
        //$file->write(		'<xf applyAlignment="false" applyBorder="false" applyFont="false" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="165" xfId="0"/>');
        //$file->write(		'<xf applyAlignment="false" applyBorder="false" applyFont="false" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="166" xfId="0"/>');
        //$file->write(		'<xf applyAlignment="false" applyBorder="false" applyFont="false" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="167" xfId="0"/>');
        //$file->write(	'</cellXfs>');
        $file->write('<cellStyles count="6">');
        $file->write('<cellStyle builtinId="0" customBuiltin="false" name="Normal" xfId="0"/>');
        $file->write('<cellStyle builtinId="3" customBuiltin="false" name="Comma" xfId="15"/>');
        $file->write('<cellStyle builtinId="6" customBuiltin="false" name="Comma [0]" xfId="16"/>');
        $file->write('<cellStyle builtinId="4" customBuiltin="false" name="Currency" xfId="17"/>');
        $file->write('<cellStyle builtinId="7" customBuiltin="false" name="Currency [0]" xfId="18"/>');
        $file->write('<cellStyle builtinId="5" customBuiltin="false" name="Percent" xfId="19"/>');
        $file->write('</cellStyles>');
        $file->write('</styleSheet>');
        $file->close();
        return $temporary_filename;
    }

    /**
     * Принимает на вход строку, возвращает номер строки в списке уникальных строк
     *
     * @param $v
     * @return int
     */
    public function setSharedString($v)
    {
        if (isset($this->shared_strings[$v])) {
            $string_value = $this->shared_strings[$v];
        } else {
            $string_value = count($this->shared_strings);
            $this->shared_strings[$v] = $string_value;
        }
        $this->shared_string_count++;//non-unique count
        return $string_value;
    }

    protected function writeSharedStringsXML()
    {
        $temporary_filename = $this->tempFilename();
        $file = new XLSXWriter_BuffererWriter($temporary_filename, $fd_flags = 'w', $check_utf8 = true);
        $file->write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n");
        $file->write(
            '<sst count="' . ($this->shared_string_count) . '" uniqueCount="' . count(
                $this->shared_strings
            ) . '" xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        );
        foreach ($this->shared_strings as $s => $c) {
            $file->write('<si><t>' . self::xmlspecialchars($s) . '</t></si>');
        }
        $file->write('</sst>');
        $file->close();

        return $temporary_filename;
    }

    protected function buildAppXML()
    {
        $app_xml = "";
        $app_xml .= '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $app_xml .= '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><TotalTime>0</TotalTime></Properties>';
        return $app_xml;
    }

    protected function buildCoreXML()
    {
        $core_xml = "";
        $core_xml .= '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $core_xml .= '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
        $core_xml .= '<dcterms:created xsi:type="dcterms:W3CDTF">' . date(
                "Y-m-d\TH:i:s.00\Z"
            ) . '</dcterms:created>';//$date_time = '2014-10-25T15:54:37.00Z';
        $core_xml .= '<dc:creator>' . self::xmlspecialchars($this->author) . '</dc:creator>';
        $core_xml .= '<cp:revision>0</cp:revision>';
        $core_xml .= '</cp:coreProperties>';
        return $core_xml;
    }

    protected function buildRelationshipsXML()
    {
        $rels_xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $rels_xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $rels_xml .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
        $rels_xml .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>';
        $rels_xml .= '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>';
        $rels_xml .= "\n";
        $rels_xml .= '</Relationships>';
        return $rels_xml;
    }

    protected function buildWorkbookXML()
    {
        $i = 0;
        $workbook_xml = "";
        $workbook_xml .= '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $workbook_xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $workbook_xml .= '<fileVersion appName="Calc"/><workbookPr backupFile="false" showObjects="all" date1904="false"/><workbookProtection/>';
        $workbook_xml .= '<bookViews><workbookView activeTab="0" firstSheet="0" showHorizontalScroll="true" showSheetTabs="true" showVerticalScroll="true" tabRatio="212" windowHeight="8192" windowWidth="16384" xWindow="0" yWindow="0"/></bookViews>';
        $workbook_xml .= '<sheets>';
        foreach ($this->sheets as $sheet_name => $sheet) {
            $workbook_xml .= '<sheet name="' . self::xmlspecialchars(
                    $sheet->sheetname
                ) . '" sheetId="' . ($i + 1) . '" state="visible" r:id="rId' . ($i + 2) . '"/>';
            $i++;
        }
        $workbook_xml .= '</sheets>';
        $workbook_xml .= '<calcPr iterateCount="100" refMode="A1" iterate="false" iterateDelta="0.001"/></workbook>';
        return $workbook_xml;
    }

    protected function buildWorkbookRelsXML()
    {
        $i = 0;
        $wkbkrels_xml = "";
        $wkbkrels_xml .= '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $wkbkrels_xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $wkbkrels_xml .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        foreach ($this->sheets as $sheet_name => $sheet) {
            $wkbkrels_xml .= '<Relationship Id="rId' . ($i + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/' . ($sheet->xmlname) . '"/>';
            $i++;
        }
        if (!empty($this->shared_strings)) {
            $wkbkrels_xml .= '<Relationship Id="rId' . (count(
                        $this->sheets
                    ) + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        }
        $wkbkrels_xml .= "\n";
        $wkbkrels_xml .= '</Relationships>';
        return $wkbkrels_xml;
    }

    protected function buildContentTypesXML()
    {
        $content_types_xml = "";
        $content_types_xml .= '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $content_types_xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $content_types_xml .= '<Override PartName="/_rels/.rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $content_types_xml .= '<Override PartName="/xl/_rels/workbook.xml.rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        foreach ($this->sheets as $sheet_name => $sheet) {
            $content_types_xml .= '<Override PartName="/xl/worksheets/' . ($sheet->xmlname) . '" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        if (!empty($this->shared_strings)) {
            $content_types_xml .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        }
        $content_types_xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $content_types_xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $content_types_xml .= '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        $content_types_xml .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        $content_types_xml .= "\n";
        $content_types_xml .= '</Types>';
        return $content_types_xml;
    }

    //------------------------------------------------------------------
    /*
     * @param $row_number int, zero based
     * @param $column_number int, zero based
     * @return Cell label/coordinates, ex: A1, C3, AA42
     * */

    //------------------------------------------------------------------
    public static function log($string)
    {
        file_put_contents(
            "php://stderr",
            date("Y-m-d H:i:s:") . rtrim(is_array($string) ? json_encode($string) : $string) . "\n"
        );
    }

    //------------------------------------------------------------------
    public static function sanitize_filename($filename
    ) //http://msdn.microsoft.com/en-us/library/aa365247%28VS.85%29.aspx
    {
        $nonprinting = array_map('chr', range(0, 31));
        $invalid_chars = array('<', '>', '?', '"', ':', '|', '\\', '/', '*', '&');
        $all_invalids = array_merge($nonprinting, $invalid_chars);
        return str_replace($all_invalids, "", $filename);
    }

    //------------------------------------------------------------------
    public static function xmlspecialchars($val)
    {
        return str_replace("'", "&#39;", htmlspecialchars($val));
    }

    //------------------------------------------------------------------
    public static function array_first_key(array $arr)
    {
        reset($arr);
        $first_key = key($arr);
        return $first_key;
    }

    //------------------------------------------------------------------
    public static function convert_date_time($date_input) //thanks to Excel::Writer::XLSX::Worksheet.pm (perl)
    {
        $seconds = 0;    # Time expressed as fraction of 24h hours in seconds
        $year = $month = $day = 0;

        $date_time = $date_input;
        $matches = array();
        if (preg_match("/(\d{4})\-(\d{2})\-(\d{2})/", $date_time, $matches)) {
            array_shift( $matches );
            list($year, $month, $day) = $matches;
        }
        if (preg_match("/(\d{2}):(\d{2}):(\d{2})/", $date_time, $matches)) {
            array_shift( $matches );
            list($hour, $min, $sec) = $matches;
            $seconds = ($hour * 3600 + $min * 60 + $sec) / (24 * 3600);
        }

        //using 1900 as epoch, not 1904, ignoring 1904 special case

        # Special cases for Excel.
        if ("$year-$month-$day" == '1899-12-31') {
            return $seconds;
        }    # Excel 1900 epoch
        if ("$year-$month-$day" == '1900-01-00') {
            return $seconds;
        }    # Excel 1900 epoch
        if ("$year-$month-$day" == '1900-02-29') {
            return 60 + $seconds;
        }    # Excel false leapday

        # We calculate the date by calculating the number of days since the epoch
        # and adjust for the number of leap days. We calculate the number of leap
        # days by normalising the year in relation to the epoch. Thus the year 2000
        # becomes 100 for 4 and 100 year leapdays and 400 for 400 year leapdays.
        $epoch = 1900;
        $offset = 0;
        $norm = 300;
        $range = $year - $epoch;

        # Set month days and check for leap year.
        $leap = (($year % 400 == 0) || (($year % 4 == 0) && ($year % 100))) ? 1 : 0;
        $mdays = array(31, ($leap ? 29 : 28), 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);

        # Some boundary checks
        if ($year < $epoch || $year > 9999) {
            return 0;
        }
        if ($month < 1 || $month > 12) {
            return 0;
        }
        if ($day < 1 || $day > $mdays[$month - 1]) {
            return 0;
        }

        # Accumulate the number of days since the epoch.
        # $days - Number of days since epoch
        $days = $day;    # Add days for current month
        $days += array_sum(array_slice($mdays, 0, $month - 1));    # Add days for past months
        $days += $range * 365;                      # Add days for past years
        $days += intval(($range) / 4);             # Add leapdays
        $days -= intval(($range + $offset) / 100); # Subtract 100 year leapdays
        $days += intval(($range + $offset + $norm) / 400);  # Add 400 year leapdays
        $days -= $leap;                                      # Already counted above

        # Adjust for Excel erroneously treating 1900 as a leap year.
        if ($days > 59) {
            $days++;
        }

        return $days + $seconds;
    }

    public function addHyperlink($sheetName, $rowNumber, $colNumber, $url, $title)
    {
        $sheet = $this->getSheet($sheetName);
        $sheet->addHyperlink( $rowNumber, $colNumber, $url, $title );
    }

    public function debug()
    {
        echo '<pre>', var_export($this->cell_formats, 1), '</pre>';
        die;
    }
    //------------------------------------------------------------------
}

class XLSXWriter_BuffererWriter
{
    protected $fd = null;
    protected $buffer = '';
    protected $check_utf8 = false;

    public function __construct($filename, $fd_fopen_flags = 'w', $check_utf8 = false)
    {
        $this->check_utf8 = $check_utf8;
        $this->fd = fopen($filename, $fd_fopen_flags);
        if ($this->fd === false) {
            XLSXWriter::log("Unable to open $filename for writing.");
        }
    }

    public function write($string)
    {
        $this->buffer .= $string;
        if (isset($this->buffer[8191])) {
            $this->purge();
        }
    }

    protected function purge()
    {
        if ($this->fd) {
            if ($this->check_utf8 && !self::isValidUTF8($this->buffer)) {
                XLSXWriter::log("Error, invalid UTF8 encoding detected.");
                $this->check_utf8 = false;
            }
            fwrite($this->fd, $this->buffer);
            $this->buffer = '';
        }
    }

    public function close()
    {
        $this->purge();
        if ($this->fd) {
            fclose($this->fd);
            $this->fd = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function ftell()
    {
        if ($this->fd) {
            $this->purge();
            return ftell($this->fd);
        }
        return -1;
    }

    public function fseek($pos)
    {
        if ($this->fd) {
            $this->purge();
            return fseek($this->fd, $pos);
        }
        return -1;
    }

    protected static function isValidUTF8($string)
    {
        if (function_exists('mb_check_encoding')) {
            return mb_check_encoding($string, 'UTF-8') ? true : false;
        }
        return preg_match("//u", $string) ? true : false;
    }
}

class XLSXWriter_Sheet {

    //------------------------------------------------------------------
    //http://office.microsoft.com/en-us/excel-help/excel-specifications-and-limits-HP010073849.aspx
    const EXCEL_2007_MAX_ROW = 1048576;
    const EXCEL_2007_MAX_COL = 16384;

    public $filename = '';
    public $sheetname = '';
    public $xmlname = '';
    public $row_count = 0;
    /**
     * @var XLSXWriter_BuffererWriter
     */
    public $file_writer;
    public $columns = array();
    public $merge_cells = array();
    public $max_cell_tag_start = 0;
    public $max_cell_tag_end = 0;
    public $finalized = false;
    private $relations = array();
    private $hyperlinks = array();
    /**
     * @var xlsxWriter
     */
    private $document;

    /**
     * XLSXWriter_Sheet constructor.
     * @param xlsxWriter $document
     * @param string $filename
     * @param string $sheetname
     * @param string $xmlname
     */
    public function __construct( xlsxWriter $document, $filename, $sheetname, $xmlname ) {
        $this->document = $document;

        $this->filename = $filename;
        $this->sheetname = $sheetname;
        $this->xmlname = $xmlname;

        $this->file_writer = new XLSXWriter_BuffererWriter( $this->filename );
    }

    public static function xlsCell($row_number, $column_number)
    {
        $n = $column_number;
        for ($r = ""; $n >= 0; $n = intval($n / 26) - 1) {
            $r = chr($n % 26 + 0x41) . $r;
        }
        return $r . ($row_number + 1);
    }

    public function addRelation( $url ) {
        $i = 1 + count( $this->relations );
        $this->relations[$i] = $url;

        return $i;
    }

    public function addHyperlink($rowNumber, $colNumber, $url, $title)
    {
        $relId = $this->addRelation( $url );
        $this->hyperlinks[] = sprintf(
            '<hyperlink ref="%s" r:id="rId%u" display="%s"/>',
            self::xlsCell( $rowNumber, $colNumber-1 ),
            $relId,
            str_replace('"', '&quot;', $title)
        );
    }

    public function hasRelations() {
        return ( count($this->relations) > 0);
    }

    public function hasHyperlinks()
    {
        return ( count($this->hyperlinks) > 0);
    }

    public function buildRelationshipXML()
    {

        $rels_xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $rels_xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach( $this->relations as $i => $url ) {
            $rels_xml .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink"'
                .' Target="'.$url.'" TargetMode="External"/>';
        }
        $rels_xml .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
        $rels_xml .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>';
        $rels_xml .= '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>';
        $rels_xml .= "\n</Relationships>";
        return $rels_xml;
    }

    public function writeProlog( $quantity ) {

        $tabselected = ($quantity == 1) ? 'true' : 'false';//only first sheet is selected
        $max_cell = XLSXWriter_Sheet::xlsCell( self::EXCEL_2007_MAX_ROW, self::EXCEL_2007_MAX_COL);//XFE1048577

        $this->file_writer->write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n");
        /** @noinspection XmlUnusedNamespaceDeclaration */
        $this->file_writer->write(
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        );
        $this->file_writer->write('<sheetPr filterMode="false">');
        $this->file_writer->write('<pageSetUpPr fitToPage="false"/>');
        $this->file_writer->write('</sheetPr>');
        $this->max_cell_tag_start = $this->file_writer->ftell();
        $this->file_writer->write('<dimension ref="A1:' . $max_cell . '"/>');
        $this->max_cell_tag_end = $this->file_writer->ftell();
        $this->file_writer->write('<sheetViews>');
        $this->file_writer->write(
            '<sheetView colorId="64" defaultGridColor="true" rightToLeft="false" showFormulas="false" showGridLines="true" showOutlineSymbols="true" showRowColHeaders="true" showZeros="true" tabSelected="' . $tabselected . '" topLeftCell="A1" view="normal" windowProtection="false" workbookViewId="0" zoomScale="100" zoomScaleNormal="100" zoomScalePageLayoutView="100">'
        );
        $this->file_writer->write('<selection activeCell="A1" activeCellId="0" pane="topLeft" sqref="A1"/>');
        $this->file_writer->write('</sheetView>');
        $this->file_writer->write('</sheetViews>');
        $this->file_writer->write('<cols>');
        $this->file_writer->write('<col collapsed="false" hidden="false" max="1025" min="1" style="0" width="11.5"/>');
        $this->file_writer->write('</cols>');
        $this->file_writer->write('<sheetData>');

    }

    public function finalizeSheet()
    {
        if ( ! $this->finalized ) {

            $this->file_writer->write('</sheetData>');

            if (!empty($this->merge_cells)) {
                $this->file_writer->write('<mergeCells>');
                foreach ($this->merge_cells as $range) {
                    $this->file_writer->write('<mergeCell ref="' . $range . '"/>');
                }
                $this->file_writer->write('</mergeCells>');
            }
            $this->writeHyperlinksXml();

            $this->file_writer->write(
                '<printOptions headings="false" gridLines="false" gridLinesSet="true" horizontalCentered="false" verticalCentered="false"/>'
            );
            $this->file_writer->write(
                '<pageMargins left="0.5" right="0.5" top="1.0" bottom="1.0" header="0.5" footer="0.5"/>'
            );
            $this->file_writer->write(
                '<pageSetup blackAndWhite="false" cellComments="none" copies="1" draft="false" firstPageNumber="1" fitToHeight="1" fitToWidth="1" horizontalDpi="300" orientation="portrait" pageOrder="downThenOver" paperSize="1" scale="100" useFirstPageNumber="true" usePrinterDefaults="false" verticalDpi="300"/>'
            );
            $this->file_writer->write('<headerFooter differentFirst="false" differentOddEven="false">');
            $this->file_writer->write(
                '<oddHeader>&amp;C&amp;&quot;Times New Roman,Regular&quot;&amp;12&amp;A</oddHeader>'
            );
            $this->file_writer->write(
                '<oddFooter>&amp;C&amp;&quot;Times New Roman,Regular&quot;&amp;12Page &amp;P</oddFooter>'
            );
            $this->file_writer->write('</headerFooter>');
            $this->file_writer->write('</worksheet>');

            $max_cell = self::xlsCell($this->row_count - 1, count($this->columns) - 1);
            $max_cell_tag = '<dimension ref="A1:' . $max_cell . '"/>';
            $padding_length = $this->max_cell_tag_end - $this->max_cell_tag_start - strlen($max_cell_tag);
            $this->file_writer->fseek($this->max_cell_tag_start);
            $this->file_writer->write($max_cell_tag . str_repeat(" ", $padding_length));
            $this->file_writer->close();

            $this->finalized = true;
        }
    }

    public function writeHyperlinksXml()
    {
        if ( !empty( $this->hyperlinks ) ) {
            $this->file_writer->write('<hyperlinks>'.implode('', $this->hyperlinks).'</hyperlinks>' );
        }
    }

    public function writeCell(
        XLSXWriter_BuffererWriter $file,
        $row_number,
        $column_number,
        $value,
        $cell_format_index
    ) {
        $cell_type = $this->document->getCellTypeByIndex( $cell_format_index );
        $cell_name = XLSXWriter_Sheet::xlsCell($row_number, $column_number);

        if (!is_scalar($value) || $value === '') { //objects, array, empty
            $file->write('<c r="' . $cell_name . '" s="' . $cell_format_index . '"/>');
        } elseif (is_string($value) && $value{0} == '=') { //formula
            $file->write(
                '<c r="' . $cell_name . '" s="' . $cell_format_index . '" t="s"><f>' . xlsxWriter::xmlspecialchars(
                    $value
                ) . '</f></c>'
            );
        } elseif ($cell_type == 'date') {
            $file->write(
                '<c r="' . $cell_name . '" s="' . $cell_format_index . '" t="n"><v>' . intval(
                    xlsxWriter::convert_date_time($value)
                ) . '</v></c>'
            );
        } elseif ($cell_type == 'datetime') {
            $file->write(
                '<c r="' . $cell_name . '" s="' . $cell_format_index . '" t="n"><v>' . xlsxWriter::convert_date_time(
                    $value
                ) . '</v></c>'
            );
        } elseif ($cell_type == 'currency' || $cell_type == 'percent' || $cell_type == 'numeric') {
            $file->write(
                '<c r="' . $cell_name . '" s="' . $cell_format_index . '" t="n"><v>' . xlsxWriter::xmlspecialchars(
                    $value
                ) . '</v></c>'
            );//int,float,currency
        } else {

            if (!is_string($value)) {
                $file->write(
                    '<c r="' . $cell_name . '" s="' . $cell_format_index . '" t="n"><v>' . ($value * 1) . '</v></c>'
                );
            } else {
                if ($value{0} != '0' && $value{0} != '+' && filter_var(
                        $value,
                        FILTER_VALIDATE_INT,
                        array('options' => array('max_range' => PHP_INT_MAX))
                    )
                ) {
                    $file->write(
                        '<c r="' . $cell_name . '" s="' . $cell_format_index . '" t="n"><v>' . ($value * 1) . '</v></c>'
                    );
                } else { //implied: ($cell_format=='string')
                    $n = $this->document->setSharedString( xlsxWriter::xmlspecialchars( $value) );
                    $file->write(
                        '<c r="' . $cell_name . '" s="' . $cell_format_index . '" t="s"><v>' . $n . '</v></c>'
                    );
                }
            }
        }
    }
}
