<?
require_once(__DIR__ . "/../libs/TasmotaService.php");

class IPS_Tasmota extends TasmotaService {
  public function Create() {
    //Never delete this line!
    parent::Create();
    $this->ConnectParent("{EE0D345A-CF31-428A-A613-33CE98E752DD}");
    //Anzahl die in der Konfirgurationsform angezeigt wird - Hier Standard auf 1
    $this->RegisterPropertyString("Topic","");
    $this->RegisterPropertyString("On","ON");
    $this->RegisterPropertyString("Off","OFF");
    $this->RegisterPropertyString("Hold","HOLD");
    $this->RegisterPropertyString("FullTopic","%prefix%/%topic%");
    $this->RegisterPropertyInteger("PowerOnState",3);
    $this->RegisterPropertyString("DeviceLanguage","en");
    $this->RegisterVariableFloat("Tasmota_RSSI", "RSSI");
    //Settings
    $this->RegisterPropertyBoolean("Power1Deactivate", false);
	  //Debug Optionen
	  $this->RegisterPropertyBoolean("Sensoren", false);
	  $this->RegisterPropertyBoolean("State", false);
	  $this->RegisterPropertyBoolean("Pow", false);
  }

  public function ApplyChanges() {
    //Never delete this line!
    parent::ApplyChanges();
    $this->ConnectParent("{EE0D345A-CF31-428A-A613-33CE98E752DD}");
    //Setze Filter fÃ¼r ReceiveData
    $this->setPowerOnState($this->ReadPropertyInteger("PowerOnState"));
    $topic = $this->ReadPropertyString("Topic");
    $this->SetReceiveDataFilter(".*".$topic.".*");
  }

  private function find_parent($array, $needle, $parent = null) {
    foreach ($array as $key => $value) {
      if (is_array($value)) {
        $pass = $parent;
        if (is_string($key)) {
          $pass = $key;
        }
        $found = $this->find_parent($value, $needle, $pass);
        if ($found !== false) {
          return $found;
        }
      } else if ($value === $needle) {
        return $parent;
      }
    }
    return false;
  }

  private function traverseArray($array, $GesamtArray) {
    foreach($array as $key=>$value)
    {
      if(is_array($value)) {
        $this->traverseArray($value, $GesamtArray);
      } else {
        $ParentKey = $this->find_parent($GesamtArray,$value);
        $this->Debug("Rekursion Tasmota ".$ParentKey."_".$key,"$key = $value","Sensoren");
        if (is_int($value) or is_float($value)){
          $ParentKey = str_replace("-", "_", $ParentKey);
          $key = str_replace("-", "_", $key);
          switch ($key) {
            case translate::Temperature:
              $variablenID = $this->RegisterVariableFloat("Tasmota_".$ParentKey."_".$key, $ParentKey." Temperatur","~Temperature");
              SetValue($this->GetIDForIdent("Tasmota_".$ParentKey."_".$key), $value);
              break;
					  case translate::Humidity:
              $variablenID = $this->RegisterVariableFloat("Tasmota_".$ParentKey."_".$key, $ParentKey." Feuchte","~Humidity.F");
              SetValue($this->GetIDForIdent("Tasmota_".$ParentKey."_".$key), $value);
              break;
            default:
              $variablenID = $this->RegisterVariableFloat("Tasmota_".$ParentKey."_".$key, $ParentKey." ".$key);
              SetValue($this->GetIDForIdent("Tasmota_".$ParentKey."_".$key), $value);
            }
          }
        }
      }
    }

    public function ReceiveData($JSONString) {
      $this->defineLanguage($this->ReadPropertyString("DeviceLanguage"));
      if (!empty($this->ReadPropertyString("Topic"))) {
        $this->SendDebug("ReceiveData JSON", $JSONString,0);
			  $data = json_decode($JSONString);
			  $off = $this->ReadPropertyString("Off");
        $on = $this->ReadPropertyString("On");
			  $hold = $this->ReadPropertyString("Hold");

			  // Buffer decodieren und in eine Variable schreiben
			  $Buffer = utf8_decode($data->Buffer);
			  // Und Diese dann wieder dekodieren
			  $Buffer = json_decode($data->Buffer);
        //PowerOnState Vairablen setzen
        if (fnmatch("*".translate::PowerOnState."*", $Buffer->MSG)) {
          $this->SendDebug("PowerOnState Topic", $Buffer->TOPIC,0);
          $this->SendDebug("PowerOnState MSG", $Buffer->MSG,0);
          $MSG = json_decode($Buffer->MSG);
          $this->setPowerOnStateInForm($MSG);
        }
        //Power Vairablen checken
        if (fnmatch("*".translate::POWER."*", $Buffer->TOPIC)) {
          $this->SendDebug("Power Topic",$Buffer->TOPIC,0);
          $this->SendDebug("Power", $Buffer->MSG,0);
          $power = explode("/", $Buffer->TOPIC);
          end($power);
          $lastKey = key($power);
          $tmpPower = "POWER1";
          if ($this->ReadPropertyBoolean("Power1Deactivate") == true) {
            $tmpPower = "POWER";
          }
          if ($power[$lastKey] <> $tmpPower) {
            $this->RegisterVariableBoolean("Tasmota_".$power[$lastKey], $power[$lastKey],"~Switch");
            $this->EnableAction("Tasmota_".$power[$lastKey]);
            switch ($Buffer->MSG) {
              case $off:
                SetValue($this->GetIDForIdent("Tasmota_".$power[$lastKey]), 0);
                break;
              case $on:
              SetValue($this->GetIDForIdent("Tasmota_".$power[$lastKey]), 1);
              break;
              case $hold:
              $this->RegisterVariableBoolean("Tasmota_".$power[$lastKey]."_Hold", $power[$lastKey]."_Hold","~Switch");
              SetValueBoolean($this->GetIDForIdent("Tasmota_".$power[$lastKey]."_Hold"), !GetValueBoolean($this->GetIDForIdent("Tasmota_".$power[$lastKey]."_Hold")));
              break;
            }
          }
        }
        //State checken
        if (fnmatch("*".translate::STATE, $Buffer->TOPIC)) {
          $myBuffer = json_decode($Buffer->MSG);
          $this->Debug("State MSG", $Buffer->MSG,"State");
          $this->Debug("State ".translate::Wifi, $myBuffer->{translate::Wifi}->RSSI,"State");
          SetValue($this->GetIDForIdent("Tasmota_RSSI"), $myBuffer->{translate::Wifi}->RSSI);
        }
        //Sensor Variablen checken
        if (fnmatch("*".translate::SENSOR, $Buffer->TOPIC)) {
          $this->Debug("Sensor MSG", $Buffer->MSG,"Sensoren");
          $this->Debug("Sensor Topic", $Buffer->TOPIC,"Sensoren");
          $myBuffer = json_decode($Buffer->MSG,true);
          $this->traverseArray($myBuffer, $myBuffer);
        }
        //POW Variablen
        if (fnmatch("*".translate::ENERGY, $Buffer->TOPIC)) {
          $myBuffer = json_decode($Buffer->MSG);
          $this->Debug("ENERGY MSG", $Buffer->MSG,"Pow");
          $this->RegisterVariableFloat("Tasmota_POWTotal", "Total", "~Electricity");
          $this->RegisterVariableFloat("Tasmota_POWYesterday", "Yesterday", "~Electricity");
          $this->RegisterVariableFloat("Tasmota_POWToday", "Today", "~Electricity");
          $this->RegisterVariableFloat("Tasmota_POWPower", "Power", "~Watt.3680");
          $this->RegisterVariableFloat("Tasmota_POWFactor", "Factor");
          $this->RegisterVariableFloat("Tasmota_POWVoltage", "Voltage", "~Volt");
          $this->RegisterVariableFloat("Tasmota_POWCurrent", "Current", "~Ampere");

          SetValue($this->GetIDForIdent("Tasmota_POWPower"), $myBuffer->{translate::Powerusage});
          SetValue($this->GetIDForIdent("Tasmota_POWTotal"), $myBuffer->{translate::Total});
          SetValue($this->GetIDForIdent("Tasmota_POWToday"), $myBuffer->{translate::Today});
          SetValue($this->GetIDForIdent("Tasmota_POWYesterday"), $myBuffer->{translate::Yesterday});
          SetValue($this->GetIDForIdent("Tasmota_POWCurrent"), $myBuffer->{translate::Current});
          SetValue($this->GetIDForIdent("Tasmota_POWVoltage"), $myBuffer->{translate::Voltage});
          SetValue($this->GetIDForIdent("Tasmota_POWFactor"), $myBuffer->{translate::Powerfactor});
        }
      }
    }
    public function RequestAction($Ident, $Value) {
      $this->SendDebug(__FUNCTION__." Ident", $Ident,0);
      $this->SendDebug(__FUNCTION__." Value", $Value,0);
      if (strlen($Ident) <> 13) {
        $power = substr($Ident,13);
      } else {
        $power = 0;
      }
      $result = $this->setPower($power, $Value);
    }
  }
?>
