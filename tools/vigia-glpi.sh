#!/bin/bash
# Vigia da VM do GLPI do laboratório.
#
# O VirtualBox deste Mac roda sem o driver de kernel ("driverless") e a ponte
# de rede (vmnet sobre o Wi-Fi) às vezes para de entregar os pacotes à VM: ela
# continua ligada, mas some da rede. Desligar e religar o cabo virtual
# (setlinkstate) costuma trazer a rede de volta em segundos, sem reiniciar a VM.
# Quando a ponte trava de vez (nem o roteador responde de dentro da VM), religar
# o cabo não adianta: depois de algumas tentativas seguidas, o vigia desliga a VM
# pelo ACPI (desligamento normal) e liga de novo em segundo plano, junto com as
# outras VMs da mesma ponte que estiverem ligadas (VIGIA_JUNTO). Para não ficar
# reiniciando em looping, espera VIGIA_ESPERA_REINICIO entre um reinício e outro
# e para depois de VIGIA_MAX_REINICIOS seguidos sem a rede voltar.
#
# Instalado em ~/.local/bin e rodado pelo LaunchAgent com.evandro.vigia-glpi.
# Os valores abaixo são os do laboratório; para outro ambiente, defina as
# variáveis VIGIA_VM, VIGIA_IP e VIGIA_GATEWAY (ex.: no EnvironmentVariables do plist).

VM="${VIGIA_VM:-GLPI Server LibreSign}"
IP="${VIGIA_IP:-192.168.15.45}"
GATEWAY="${VIGIA_GATEWAY:-192.168.15.1}"
INTERVALO="${VIGIA_INTERVALO:-20}"   # segundos entre testes
REINICIAR_APOS="${VIGIA_REINICIAR_APOS:-3}"          # religadas de cabo sem sucesso antes de reiniciar a VM
ESPERA_REINICIO="${VIGIA_ESPERA_REINICIO:-1800}"     # segundos mínimos entre dois reinícios
MAX_REINICIOS="${VIGIA_MAX_REINICIOS:-3}"            # reinícios seguidos sem a rede voltar; depois só registra
JUNTO="${VIGIA_JUNTO:-Zabbix 7.4}"                   # outras VMs na mesma ponte, separadas por vírgula
PAUSA_APOS_ACAO="${VIGIA_PAUSA:-60}"                   # tempo para a VM se estabilizar depois de religar
VBM="${VIGIA_VBOXMANAGE:-/Applications/VirtualBox.app/Contents/MacOS/VBoxManage}"
LOG="${VIGIA_LOG:-$HOME/Library/Logs/vigia-glpi.log}"

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >> "$LOG"; }

no_ar() {
    ping -c 1 -t 2 "$IP" >/dev/null 2>&1 && return 0
    # um ping perdido não basta: confirma com mais dois e com o HTTP
    ping -c 2 -t 3 "$IP" >/dev/null 2>&1 && return 0
    curl -s -m 5 -o /dev/null "http://$IP/" && return 0
    return 1
}

rodando() { "$VBM" list runningvms 2>/dev/null | grep -q "\"$1\""; }

# desliga pelo ACPI (força só se não desligar em 2 min) e liga de novo sem janela
reinicia_vm() {
    local vm="$1"
    rodando "$vm" || return 0
    log "reiniciando a VM \"$vm\" (ACPI)"
    "$VBM" controlvm "$vm" acpipowerbutton >/dev/null 2>&1
    for _ in $(seq 1 40); do rodando "$vm" || break; sleep 3; done
    if rodando "$vm"; then
        log "a VM \"$vm\" não desligou pelo ACPI em 2 min; desligando à força"
        "$VBM" controlvm "$vm" poweroff >/dev/null 2>&1
        sleep 5
    fi
    "$VBM" startvm "$vm" --type headless >/dev/null 2>&1 && log "VM \"$vm\" ligada de novo" || log "falha ao ligar a VM \"$vm\""
}

log "vigia iniciado (VM \"$VM\", $IP, a cada ${INTERVALO}s; reinicia após $REINICIAR_APOS religadas sem sucesso)"
quedas=0
reinicios=0
ultimo_reinicio=0
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
            reinicios=0
        elif [ "$quedas" -ge "$REINICIAR_APOS" ] && [ "$reinicios" -lt "$MAX_REINICIOS" ] && [ $(( $(date +%s) - ultimo_reinicio )) -ge "$ESPERA_REINICIO" ]; then
            log "não voltou com o religar do cabo ($quedas vezes); reiniciando as VMs da ponte"
            reinicia_vm "$VM"
            IFS=',' read -ra outras <<< "$JUNTO"
            for outra in "${outras[@]}"; do
                outra="$(echo "$outra" | xargs)"
                [ -n "$outra" ] && [ "$outra" != "$VM" ] && reinicia_vm "$outra"
            done
            reinicios=$((reinicios + 1))
            ultimo_reinicio=$(date +%s)
            for _ in $(seq 1 40); do no_ar && break; sleep 3; done
            if no_ar; then
                log "GLPI de volta depois do reinício"
                quedas=0
                reinicios=0
            else
                log "GLPI continua fora depois do reinício ($reinicios de $MAX_REINICIOS)"
            fi
        elif [ "$reinicios" -ge "$MAX_REINICIOS" ]; then
            log "não voltou; já foram $reinicios reinícios seguidos, só registrando (verifique o VirtualBox e o Wi-Fi do Mac)"
        else
            log "não voltou com o religar do cabo"
        fi
        sleep "$PAUSA_APOS_ACAO"
        continue
    fi
    sleep "$INTERVALO"
done
