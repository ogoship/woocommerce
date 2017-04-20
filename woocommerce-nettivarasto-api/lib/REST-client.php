<?php

class NettivarastoAPI_RESTclient
{
  private $api = null;
  private $method = '';
  private $url = '';
  private $shaParameters = '';
  private $getParameters = array();
  private $dataArray = array();
  private $json = true;
  
  function __construct(NettivarastoAPI $api, $method, $url, $shaParameters)
  {
    $this->api = $api;
    $this->method = $method;
    $this->url = $url;
    $this->shaParameters = $shaParameters;
  }
  
  function addGetParameter($key, $value)
  {
    $this->getParameters[$key] = $value;
  }
  
  function setPostData($dataArray)
  {
    if (is_array($dataArray))
    {
      $this->dataArray = $dataArray;
    }
  }
  
  function useJson()
  {
    $this->json = true;
  }
  
  function useXML()
  {
    $this->json = false;
  }

  function execute(&$resultArray)
  {
    // Clear old error messages.
    $this->api->setError('');
    
    // Open HTTP-connection.
    $ip = 'service.nettivarasto.fi';
    $fp = @fsockopen($ip, 80, $errno, $errstr, 5);
    $result = '';
    if (!$fp)
    {
      return false;
    }

    // Remove '/' from the end of url.
    if ($this->url[strlen($this->url) - 1] == '/')
    {
      $this->url = substr($this->url, 0, -1);
    }

    // Create url.
    $this->url = '/merchant/' . urlencode($this->api->getMerchantID()) . $this->url . '?SHA1=' . $this->api->getSHA1($this->shaParameters);

    // Append other parameters.
    foreach ($this->getParameters as $key => $value)
    {
      $this->url .= '&' . urlencode($key) . '=' . urlencode($value);
    }

    // Create HTTP-request.
    $out = $this->method . ' ' . $this->url . " HTTP/1.1\r\n";
    $out .= 'Host: ' . $ip . "\r\n";
    if ($this->json)
    {
      $out .= "Content-type: application/json\r\n";
    }
    else
    {
      $out .= "Content-type: application/xml\r\n";
    }
    $out .= "Connection: close\r\n";

    // Append data to HTTP-request.
    $data = false;
    if (count($this->dataArray) > 0)
    {
      if ($this->json)
      {
        // JSON.
        $data = json_encode($this->dataArray);
      }
      else
      {
        // XML.

        /// \todo XML
        $data = '<todo></todo>';
      }

      $out .= 'Content-Length: ' . strlen($data) . "\r\n";
    }
    $out .= "\r\n";
    if ($data !== false)
    {
      $out .= $data;
    }

    /// \todo REMOVE
    ///echo '<div style="display: none">$out = ';
    ///print_r($out);
    ///echo "</div>\n";
    
    // Send HTTP-request.
    if (@fwrite($fp, $out) === false)
    {
      @fclose($fp);
      return false;
    }
    while (!@feof($fp))
    {
      $result .= fgets($fp, 128);
    }
    @fclose($fp);

    /// \todo REMOVE
//    print_r($result);
    //return;

    // Extract data from HTTP-response.
    $header = true;
    $data = '';
    foreach(preg_split("/(\r?\n)/", $result) as $line)
    {
      if ($line == '')
      {
        $header = false;
      }
      else if (!$header)
      {
        $data .= $line . "\n";
      }
    }
    if ($data == '')
    {
      return false;
    }

    // Response data type.
    if ($this->json)
    {
      // JSON response.
      $jsonData = @json_decode($data, true);
      $resultArray = $jsonData;
      if ($jsonData === null)
      {
        return false;
      }

      /// \todo REMOVE
      //print_r($data);

      if (is_array($jsonData) &&
          array_key_exists('Response', $jsonData) &&
          is_array($jsonData['Response']) && 
          array_key_exists('Info', $jsonData['Response']) &&
          is_array($jsonData['Response']['Info']) &&
          array_key_exists('@Success', $jsonData['Response']['Info']))
      {
        if ($jsonData['Response']['Info']['@Success'] == 'true')
        {
          return true;
        }
        else
        {
          if (array_key_exists('@Error', $jsonData['Response']['Info']))
          {
            $this->api->setError($jsonData['Response']['Info']['@Error']);
          }

          return false;
        }
      }
      else
      {
        return false;
      }
    }
    else
    {
      // XML response.
      
      /// \todo XML
      return false;
    }
  }
}

?>
