#!/bin/bash
# Vigia da VM do GLPI do laboratório.
#
# O VirtualBox deste Mac roda sem o driver de kernel ("driverless") e a ponte
# de rede (vmnet sobre o Wi-Fi) às vezes para de entregar os pacotes à VM: ela
# continua ligada, mas some da rede. Desligar e religar o cabo virtual
# (setlinkstate) traz a rede de volta em segundos, sem reiniciar a VM.
#
# Instalado em ~/.local/bin e rodado pelo LaunchAgent com.evandro.vigia-glpi.
# Os valores abaixo são os do laboratório; para outro ambiente, defina as
# variáveis VIGIA_VM, VIGIA_IP e VIGIA_GATEWAY (ex.: no EnvironmentVariables do plist).

VM="${VIGIA_VM:-GLPI Server LibreSign}"
IP="${VIGIA_IP:-192.168.15.45}"
GATEWAY="${VIGIA_GATEWAY:-192.168.15.1}"
INTERVALO="${VIGIA_INTERVALO:-20}"   # segundos entre testes
PAUSA_APOS_ACAO=60                    # tempo para a VM se estabilizar depois de religar
VBM=/Applications/VirtualBox.app/Contents/MacOS/VBoxManage
LOG="$HOME/Library/Logs/vigia-glpi.log"

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >> "$LOG"; }

no_ar() {
    ping -c 1 -t 2 "$IP" >/dev/null 2>&1 && return 0
    # um ping perdido não basta: confirma com mais dois e com o HTTP
    ping -c 2 -t 3 "$IP" >/dev/null 2>&1 && return 0
    curl -s -m 5 -o /dev/null "http://$IP/" && return 0
    return 1
}

log "vigia iniciado (VM \"$VM\", $IP, a cada ${INTERVALO}s)"
quedas=0
while true; do
    if no_ar; then
        quedas=0
    elif ! ping -c 1 -t 2 "$GATEWAY" >/dev/null 2>&1; then
        : # o próprio Mac está sem rede: não é a VM, não mexe em nada
    elif ! "$VBM" list runningvms 2>/dev/null | grep -q "\"$VM\""; then
        : # VM desligada de propósito: não liga sozinha
    else
        quedas=$((quedas + 1))
        log "GLPI sem rede (queda $quedas seguida); religando o cabo virtual"
        "$VBM" controlvm "$VM" setlinkstate1 off >/dev/null 2>&1
        sleep 3
        "$VBM" controlvm "$VM" setlinkstate1 on >/dev/null 2>&1
        voltou=0
        for _ in 1 2 3 4 5 6 7 8 9 10; do
            if ping -c 1 -t 2 "$IP" >/dev/null 2>&1; then voltou=1; break; fi
            sleep 3
        done
        if [ "$voltou" = 1 ]; then
            log "rede da VM voltou"
            quedas=0
        else
            log "não voltou com o religar do cabo; reinicie a VM (ACPI) se continuar"
        fi
        sleep "$PAUSA_APOS_ACAO"
        continue
    fi
    sleep "$INTERVALO"
done
