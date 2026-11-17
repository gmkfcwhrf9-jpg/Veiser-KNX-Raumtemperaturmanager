
<?php

class VeiserKNXRaumtemperaturmanager extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // HTML-Visualisierung
        $this->SetVisualizationType(1);

        // Allgemein
        $this->RegisterPropertyString('RoomName', 'Raum');

        // KNX-Datenpunkte
        $this->RegisterPropertyInteger('ModeID', 0);
        $this->RegisterPropertyInteger('SetpointID', 0);
        $this->RegisterPropertyInteger('FeedbackID', 0);
        $this->RegisterPropertyInteger('RoomTemperatureID', 0);

        // Temperatur-Einstellungen
        $this->RegisterPropertyFloat('Comfort', 21.0);
        $this->RegisterPropertyFloat('NightOffset', 3.0);
        $this->RegisterPropertyFloat('Away', 18.0);
        $this->RegisterPropertyFloat('Frost', 8.0);

        // KNX-Betriebsmoduswerte
        $this->RegisterPropertyInteger('ComfortMode', 1);
        $this->RegisterPropertyInteger('NightMode', 2);
        $this->RegisterPropertyInteger('AwayMode', 3);
        $this->RegisterPropertyInteger('FrostMode', 4);

        // Sicherheit
        $this->RegisterPropertyBoolean('DryRun', true);

        // Interne Variablen
        $this->RegisterVariableInteger(
            'OperatingMode',
            'Betriebsmodus',
            '',
            10
        );
        $this->EnableAction('OperatingMode');

        $this->RegisterVariableFloat(
            'ComfortSetting',
            'Komforttemperatur',
            '~Temperature',
            20
        );
        $this->EnableAction('ComfortSetting');

        $this->RegisterVariableFloat(
            'ManualSetpoint',
            'Solltemperatur',
            '~Temperature',
            30
        );
        $this->EnableAction('ManualSetpoint');

        $this->RegisterVariableFloat(
            'CalculatedSetpoint',
            'Berechneter Sollwert',
            '~Temperature',
            40
        );

        $this->RegisterVariableFloat(
            'ActualSetpoint',
            'KNX Sollwert-Rueckmeldung',
            '~Temperature',
            50
        );

        $this->RegisterVariableFloat(
            'RoomTemperature',
            'Raumtemperatur Ist',
            '~Temperature',
            60
        );

        $this->RegisterVariableString(
            'Info',
            'Status / Diagnose',
            '',
            70
        );

        $this->RegisterAttributeBoolean(
            'Initialized',
            false
        );
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // KNX-Änderungen überwachen
        foreach ([
            'ModeID',
            'FeedbackID',
            'RoomTemperatureID'
        ] as $property) {
            $id = $this->ReadPropertyInteger($property);

            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterMessage($id, 10603);
            }
        }

        // Eigene Variablen überwachen
        foreach ([
            'OperatingMode',
            'ComfortSetting',
            'ManualSetpoint',
            'ActualSetpoint',
            'RoomTemperature'
        ] as $ident) {
            $this->RegisterMessage(
                $this->GetIDForIdent($ident),
                10603
            );
        }

        if (!$this->ReadAttributeBoolean('Initialized')) {
            $comfort = $this->ReadPropertyFloat('Comfort');

            SetValue(
                $this->GetIDForIdent('ComfortSetting'),
                $comfort
            );

            SetValue(
                $this->GetIDForIdent('OperatingMode'),
                1
            );

            SetValue(
                $this->GetIDForIdent('ManualSetpoint'),
                $comfort
            );

            SetValue(
                $this->GetIDForIdent('CalculatedSetpoint'),
                $comfort
            );

            $this->WriteAttributeBoolean(
                'Initialized',
                true
            );
        }

        $this->SyncKNX();
        $this->UpdateTile();
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'OperatingMode':
                $this->SetMode((int)$Value);
                break;

            case 'ManualSetpoint':
                $this->SetTemperature((float)$Value);
                break;

            case 'ComfortSetting':
                $this->SetComfort((float)$Value);
                break;

            default:
                throw new Exception(
                    'Unbekannte Aktion: ' . $Ident
                );
        }
    }

    private function ModeValues()
    {
        return [
            1 => $this->ReadPropertyInteger('ComfortMode'),
            2 => $this->ReadPropertyInteger('NightMode'),
            3 => $this->ReadPropertyInteger('AwayMode'),
            4 => $this->ReadPropertyInteger('FrostMode')
        ];
    }

    private function TemperatureForMode($mode)
    {
        $comfort = (float)GetValue(
            $this->GetIDForIdent('ComfortSetting')
        );

        switch ($mode) {
            case 1:
                return $comfort;

            case 2:
                return $comfort -
                    $this->ReadPropertyFloat('NightOffset');

            case 3:
                return $this->ReadPropertyFloat('Away');

            case 4:
                return $this->ReadPropertyFloat('Frost');
        }

        return $comfort;
    }

    private function WriteKNX($property, $value)
    {
        $id = $this->ReadPropertyInteger($property);

        if ($this->ReadPropertyBoolean('DryRun')) {
            $this->SetInfo(
                'TESTMODUS: ' . $property .
                ' = ' . $value
            );
            return;
        }

        if ($id <= 0 || !IPS_VariableExists($id)) {
            $this->SetInfo(
                'KNX-Datenpunkt fehlt: ' . $property
            );
            return;
        }

        try {
            IPS_RequestAction($id, $value);
            $this->SetInfo(
                'KNX-Befehl gesendet: ' . $property
            );
        } catch (Throwable $e) {
            $this->SetInfo(
                'KNX-Fehler: ' . $e->getMessage()
            );
        }
    }

    private function SetMode($mode)
    {
        if ($mode < 1 || $mode > 4) {
            return;
        }

        $temperature = $this->TemperatureForMode($mode);

        SetValue(
            $this->GetIDForIdent('OperatingMode'),
            $mode
        );

        SetValue(
            $this->GetIDForIdent('CalculatedSetpoint'),
            $temperature
        );

        SetValue(
            $this->GetIDForIdent('ManualSetpoint'),
            $temperature
        );

        $values = $this->ModeValues();

        $this->WriteKNX('ModeID', $values[$mode]);
        $this->WriteKNX('SetpointID', $temperature);

        $this->UpdateTile();
    }

    private function SetTemperature($temperature)
    {
        $temperature = round(
            max(5, min(35, $temperature)) * 2
        ) / 2;

        SetValue(
            $this->GetIDForIdent('ManualSetpoint'),
            $temperature
        );

        $this->WriteKNX('SetpointID', $temperature);

        $this->UpdateTile();
    }

    private function SetComfort($temperature)
    {
        $temperature = round(
            max(5, min(35, $temperature)) * 2
        ) / 2;

        SetValue(
            $this->GetIDForIdent('ComfortSetting'),
            $temperature
        );

        $mode = (int)GetValue(
            $this->GetIDForIdent('OperatingMode')
        );

        $this->SetMode($mode);
    }

    private function SyncKNX()
    {
        // Sollwert-Rückmeldung
        $feedbackID = $this->ReadPropertyInteger('FeedbackID');

        if (
            $feedbackID > 0 &&
            IPS_VariableExists($feedbackID)
        ) {
            SetValue(
                $this->GetIDForIdent('ActualSetpoint'),
                (float)GetValue($feedbackID)
            );
        }

        // Echte Raumtemperatur
        $roomID = $this->ReadPropertyInteger('RoomTemperatureID');

        if (
            $roomID > 0 &&
            IPS_VariableExists($roomID)
        ) {
            SetValue(
                $this->GetIDForIdent('RoomTemperature'),
                (float)GetValue($roomID)
            );
        }

        // KNX-Betriebsmodus einlesen
        $modeID = $this->ReadPropertyInteger('ModeID');

        if (
            $modeID > 0 &&
            IPS_VariableExists($modeID)
        ) {
            $knxMode = (int)GetValue($modeID);

            foreach ($this->ModeValues() as $mode => $value) {
                if ($knxMode === $value) {
                    $currentMode = (int)GetValue(
                        $this->GetIDForIdent('OperatingMode')
                    );

                    if ($currentMode !== $mode) {
                        SetValue(
                            $this->GetIDForIdent('OperatingMode'),
                            $mode
                        );

                        $temperature =
                            $this->TemperatureForMode($mode);

                        SetValue(
                            $this->GetIDForIdent('CalculatedSetpoint'),
                            $temperature
                        );

                        SetValue(
                            $this->GetIDForIdent('ManualSetpoint'),
                            $temperature
                        );
                    }

                    break;
                }
            }
        }
    }

    public function MessageSink(
        $TimeStamp,
        $SenderID,
        $Message,
        $Data
    ) {
        parent::MessageSink(
            $TimeStamp,
            $SenderID,
            $Message,
            $Data
        );

        if ($Message !== 10603) {
            return;
        }

        $this->SyncKNX();
        $this->UpdateTile();
    }

    private function SetInfo($message)
    {
        SetValue(
            $this->GetIDForIdent('Info'),
            $message
        );
    }

    private function TileState()
    {
        $comfort = (float)GetValue(
            $this->GetIDForIdent('ComfortSetting')
        );

        $roomID = $this->ReadPropertyInteger(
            'RoomTemperatureID'
        );

        $roomTemperature = null;

        if (
            $roomID > 0 &&
            IPS_VariableExists($roomID)
        ) {
            $roomTemperature = (float)GetValue($roomID);
        }

        return [
            'room' => $this->ReadPropertyString('RoomName'),

            'mode' => (int)GetValue(
                $this->GetIDForIdent('OperatingMode')
            ),

            'setpoint' => (float)GetValue(
                $this->GetIDForIdent('ManualSetpoint')
            ),

            'feedback' => (float)GetValue(
                $this->GetIDForIdent('ActualSetpoint')
            ),

            'roomTemperature' => $roomTemperature,

            'comfort' => $comfort,

            'night' => $comfort -
                $this->ReadPropertyFloat('NightOffset'),

            'away' => $this->ReadPropertyFloat('Away'),

            'frost' => $this->ReadPropertyFloat('Frost'),

            'dryRun' => $this->ReadPropertyBoolean('DryRun'),

            'info' => (string)GetValue(
                $this->GetIDForIdent('Info')
            )
        ];
    }

    private function UpdateTile()
    {
        $this->UpdateVisualizationValue(
            json_encode(
                $this->TileState(),
                JSON_UNESCAPED_UNICODE
            )
        );
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(
            __DIR__ . '/module.html'
        );

        $state = json_encode(
            $this->TileState(),
            JSON_UNESCAPED_UNICODE |
            JSON_HEX_TAG |
            JSON_HEX_AMP |
            JSON_HEX_APOS |
            JSON_HEX_QUOT
        );

        return str_replace(
            '__INITIAL_STATE__',
            $state,
            $html
        );
    }
}
