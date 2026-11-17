<?php
class VeiserKNXRaumtemperaturmanager extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->SetVisualizationType(INSTANCE_VISUALIZATION_TYPE_HTML);
        $this->RegisterPropertyString('RoomName', 'Raum');
        foreach (['ModeID', 'SetpointID', 'FeedbackID'] as $key) {
            $this->RegisterPropertyInteger($key, 0);
        }
        foreach (['Comfort' => 21.0, 'NightOffset' => 3.0, 'Away' => 18.0, 'Frost' => 8.0] as $key => $value) {
            $this->RegisterPropertyFloat($key, $value);
        }
        // Deliberately configurable: KNX mode values vary by datapoint and installation.
        foreach (['ComfortMode' => 1, 'NightMode' => 2, 'AwayMode' => 3, 'FrostMode' => 4] as $key => $value) {
            $this->RegisterPropertyInteger($key, $value);
        }
        $this->RegisterPropertyBoolean('DryRun', true);
        $this->RegisterAttributeString('SubscribedIDs', '[]');
        $this->RegisterAttributeBoolean('ComfortInitialized', false);
        $this->RegisterAttributeInteger('LastMode', -2147483647);
        $this->RegisterVariableInteger('OperatingMode', 'Betriebsmodus (1 Komfort, 2 Nacht, 3 Abwesend, 4 Frost)', '', 5);
        $this->EnableAction('OperatingMode');
        $this->RegisterVariableFloat('ComfortSetting', 'Komforttemperatur', '~Temperature', 10);
        $this->EnableAction('ComfortSetting');
        $this->RegisterVariableFloat('ManualSetpoint', 'Manueller Sollwert', '~Temperature', 20);
        $this->EnableAction('ManualSetpoint');
        $this->RegisterVariableFloat('CalculatedSetpoint', 'Berechneter Sollwert', '~Temperature', 30);
        $this->RegisterVariableFloat('ActualSetpoint', 'KNX Rueckmeldung', '~Temperature', 40);
        $this->RegisterVariableString('Info', 'Status / Diagnose', '', 50);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        foreach (json_decode($this->ReadAttributeString('SubscribedIDs'), true) ?: [] as $id) {
            if (IPS_VariableExists((int) $id)) {
                $this->UnregisterMessage((int) $id, VM_UPDATE);
            }
        }
        $ids = [];
        foreach (['ModeID', 'SetpointID', 'FeedbackID'] as $key) {
            $id = $this->ReadPropertyInteger($key);
            if ($id > 0 && IPS_VariableExists($id)) {
                $ids[] = $id;
            }
        }
        foreach (array_unique($ids) as $id) {
            $this->RegisterMessage($id, VM_UPDATE);
        }
        $this->WriteAttributeString('SubscribedIDs', json_encode(array_values(array_unique($ids))));
        if (!$this->ReadAttributeBoolean('ComfortInitialized')) {
            SetValue($this->GetIDForIdent('ComfortSetting'), $this->ReadPropertyFloat('Comfort'));
            $this->WriteAttributeBoolean('ComfortInitialized', true);
        }
        $this->UpdateFeedback();
        $this->UpdateModeDisplay();
        foreach (['OperatingMode', 'ComfortSetting', 'ManualSetpoint', 'CalculatedSetpoint', 'ActualSetpoint', 'Info'] as $ident) {
            $this->RegisterMessage($this->GetIDForIdent($ident), VM_UPDATE);
        }
        if (!$this->IsValidConfiguration()) {
            $this->SetValue('Info', 'Konfiguration unvollstaendig oder ungueltig; kein KNX-Schreiben.');
            $this->SetStatus(200);
            return;
        }
        $this->SetStatus(102);
        $this->Calculate(false); // no writes on restart or configuration reload
    }

    private function IsValidConfiguration(): bool
    {
        $modeID = $this->ReadPropertyInteger('ModeID');
        $setpointID = $this->ReadPropertyInteger('SetpointID');
        if ($modeID <= 0 || $setpointID <= 0 || $modeID === $setpointID || !IPS_VariableExists($modeID) || !IPS_VariableExists($setpointID)) {
            return false;
        }
        $modes = array_map(function ($name) { return $this->ReadPropertyInteger($name); }, ['ComfortMode', 'NightMode', 'AwayMode', 'FrostMode']);
        return count(array_unique($modes)) === 4;
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'OperatingMode':
                $selection = (int) $Value;
                if ($selection < 1 || $selection > 4 || (string) $selection !== (string) $Value) {
                    throw new Exception('Ungueltiger Betriebsmodus');
                }
                if (!$this->IsValidConfiguration()) {
                    throw new Exception('KNX-Konfiguration ungueltig');
                }
                $modeValues = [1 => $this->ReadPropertyInteger('ComfortMode'), 2 => $this->ReadPropertyInteger('NightMode'), 3 => $this->ReadPropertyInteger('AwayMode'), 4 => $this->ReadPropertyInteger('FrostMode')];
                $knxValue = $modeValues[$selection];
                if ($this->ReadPropertyBoolean('DryRun')) {
                    SetValue($this->GetIDForIdent('OperatingMode'), $selection);
                    $this->CalculateForMode($knxValue, true);
                    SetValue($this->GetIDForIdent('Info'), 'TESTMODUS: Modus ' . $selection . ' simuliert; keine KNX-Telegramme.');
                } else {
                    // KNX mode is the source of truth; a bus echo triggers calculation.
                    RequestAction($this->ReadPropertyInteger('ModeID'), $knxValue);
                    $this->UpdateModeDisplay();
                    if ((int) GetValue($this->ReadPropertyInteger('ModeID')) === $knxValue && $knxValue !== $this->ReadAttributeInteger('LastMode')) {
                        $this->Calculate(true);
                    }
                }
                break;
            case 'ComfortSetting':
                $value = $this->ValidateTemperature($Value);
                SetValue($this->GetIDForIdent('ComfortSetting'), $value);
                $this->Calculate(true);
                break;
            case 'ManualSetpoint':
                $this->WriteTemperature($this->ValidateTemperature($Value), 'Manuelle Vorgabe');
                break;
            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);
        if ($Message !== VM_UPDATE) {
            return;
        }
        foreach (['OperatingMode', 'ComfortSetting', 'ManualSetpoint', 'CalculatedSetpoint', 'ActualSetpoint', 'Info'] as $ident) {
            if ($SenderID === $this->GetIDForIdent($ident)) {
                $this->UpdateVisualizationValue(json_encode($this->TileState(), JSON_UNESCAPED_UNICODE));
                return;
            }
        }
        if ($SenderID === $this->ReadPropertyInteger('FeedbackID')) {
            $this->UpdateFeedback();
        }
        if ($SenderID === $this->ReadPropertyInteger('SetpointID')) {
            // Manual changes from KNX/visualization must not be overwritten.
            SetValue($this->GetIDForIdent('ManualSetpoint'), (float) GetValue($SenderID));
        }
        if ($SenderID === $this->ReadPropertyInteger('ModeID')) {
            $this->UpdateModeDisplay();
            $mode = (int) GetValue($SenderID);
            if ($mode !== $this->ReadAttributeInteger('LastMode')) {
                $this->Calculate(true);
            }
        }
    }

    private function UpdateModeDisplay(): void
    {
        $id = $this->ReadPropertyInteger('ModeID');
        if ($id <= 0 || !IPS_VariableExists($id)) { return; }
        $mode = (int) GetValue($id);
        $values = [1 => $this->ReadPropertyInteger('ComfortMode'), 2 => $this->ReadPropertyInteger('NightMode'), 3 => $this->ReadPropertyInteger('AwayMode'), 4 => $this->ReadPropertyInteger('FrostMode')];
        foreach ($values as $selection => $knxValue) {
            if ($mode === $knxValue) { SetValue($this->GetIDForIdent('OperatingMode'), $selection); return; }
        }
    }

    private function UpdateFeedback(): void
    {
        $id = $this->ReadPropertyInteger('FeedbackID');
        if ($id > 0 && IPS_VariableExists($id)) {
            SetValue($this->GetIDForIdent('ActualSetpoint'), (float) GetValue($id));
        }
    }

    private function Calculate(bool $write): void
    {
        if (!$this->IsValidConfiguration()) {
            return;
        }
        $mode = (int) GetValue($this->ReadPropertyInteger('ModeID'));
        $this->CalculateForMode($mode, $write);
    }

    private function CalculateForMode(int $mode, bool $write): void
    {
        $comfort = (float) GetValue($this->GetIDForIdent('ComfortSetting'));
        $mapping = [
            $this->ReadPropertyInteger('ComfortMode') => $comfort,
            $this->ReadPropertyInteger('NightMode') => $comfort - $this->ReadPropertyFloat('NightOffset'),
            $this->ReadPropertyInteger('AwayMode') => $this->ReadPropertyFloat('Away'),
            $this->ReadPropertyInteger('FrostMode') => $this->ReadPropertyFloat('Frost')
        ];
        if (!array_key_exists($mode, $mapping)) {
            SetValue($this->GetIDForIdent('Info'), 'Unbekannter KNX-Modus: ' . $mode);
            return;
        }
        $target = $this->ValidateTemperature($mapping[$mode]);
        SetValue($this->GetIDForIdent('CalculatedSetpoint'), $target);
        $this->WriteAttributeInteger('LastMode', $mode);
        if ($write) {
            $this->WriteTemperature($target, 'Betriebsmodus ' . $mode);
        } else {
            SetValue($this->GetIDForIdent('Info'), 'Berechnet: ' . $target . ' °C; kein Schreibzugriff beim Start.');
        }
    }

    private function ValidateTemperature($value): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 5.0 || (float) $value > 35.0) {
            throw new Exception('Temperatur ausserhalb 5 bis 35 °C');
        }
        return round((float) $value, 1);
    }

    private function WriteTemperature(float $target, string $reason): void
    {
        if (!$this->IsValidConfiguration()) {
            throw new Exception('KNX-Konfiguration ungueltig');
        }
        SetValue($this->GetIDForIdent('ManualSetpoint'), $target);
        if ($this->ReadPropertyBoolean('DryRun')) {
            SetValue($this->GetIDForIdent('Info'), 'TESTMODUS: ' . $reason . ' -> ' . $target . ' °C (nicht gesendet)');
            return;
        }
        $id = $this->ReadPropertyInteger('SetpointID');
        if (abs((float) GetValue($id) - $target) < 0.05) {
            SetValue($this->GetIDForIdent('Info'), 'Sollwert bereits ' . $target . ' °C; kein Telegramm.');
            return;
        }
        RequestAction($id, $target);
        SetValue($this->GetIDForIdent('Info'), $reason . ' -> ' . $target . ' °C gesendet; Rueckmeldung separat pruefen.');
    }
    private function TileState(): array
    {
        $comfort = (float) GetValue($this->GetIDForIdent('ComfortSetting'));
        return [
            'room' => $this->ReadPropertyString('RoomName'),
            'mode' => (int) GetValue($this->GetIDForIdent('OperatingMode')),
            'setpoint' => (float) GetValue($this->GetIDForIdent('ManualSetpoint')),
            'feedback' => (float) GetValue($this->GetIDForIdent('ActualSetpoint')),
            'comfort' => $comfort,
            'night' => $comfort - $this->ReadPropertyFloat('NightOffset'),
            'away' => $this->ReadPropertyFloat('Away'),
            'frost' => $this->ReadPropertyFloat('Frost'),
            'dryRun' => $this->ReadPropertyBoolean('DryRun'),
            'info' => (string) GetValue($this->GetIDForIdent('Info'))
        ];
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $state = json_encode($this->TileState(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return str_replace('__INITIAL_STATE__', $state, $html);
    }

}
