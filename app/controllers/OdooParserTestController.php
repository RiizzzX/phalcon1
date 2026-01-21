<?php

class OdooParserTestController extends ControllerBase
{
    public function indexAction()
    {
        // XML response yang kita tahu berhasil dari raw test
        $xmlResponse = "<?xml version='1.0'?>
<methodResponse>
<params>
<param>
<value><array><data>
<value><struct>
<member>
<name>id</name>
<value><int>1</int></value>
</member>
<member>
<name>name</name>
<value><string>Canon EOS R5</string></value>
</member>
<member>
<name>code</name>
<value><string>CAM-001</string></value>
</member>
<member>
<name>status</name>
<value><string>available</string></value>
</member>
<member>
<name>daily_rate</name>
<value><double>500000.0</double></value>
</member>
</struct></value>
</data></array></value>
</param>
</params>
</methodResponse>";

        // Test SimpleXML parsing
        $sxml = simplexml_load_string($xmlResponse);
        $value = $sxml->params->param->value;
        
        $result = $this->parseValue($value);
        
        $this->view->xmlResponse = htmlspecialchars($xmlResponse);
        $this->view->parsed = print_r($result, true);
        $this->view->count = is_array($result) ? count($result) : 0;
    }
    
    private function parseValue($value)
    {
        if (!$value) return null;
        
        foreach ($value->children() as $type => $content) {
            switch ($type) {
                case 'int':
                case 'i4':
                    return (int)(string)$content;
                case 'boolean':
                    return (bool)(int)(string)$content;
                case 'string':
                    return (string)$content;
                case 'double':
                    return (float)(string)$content;
                case 'array':
                    $result = [];
                    if (isset($content->data->value)) {
                        foreach ($content->data->value as $item) {
                            $result[] = $this->parseValue($item);
                        }
                    }
                    return $result;
                case 'struct':
                    $result = [];
                    foreach ($content->member as $member) {
                        $key = (string)$member->name;
                        $result[$key] = $this->parseValue($member->value);
                    }
                    return $result;
            }
        }
        
        return (string)$value;
    }
}
