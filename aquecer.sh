#!/bin/sh
# Aquece o cache do painel (consulta padrão de 7 dias) para a página abrir com os números na hora.
B=http://127.0.0.1/datacenter360/api.php
T=$(date +%F); F=$(date -d '6 days ago' +%F)
curl -s -m 120 -o /dev/null "$B?action=data&from=$F&to=$T&nocache=1"
curl -s -m 60 -o /dev/null "$B?action=office&nocache=1"
curl -s -m 60 -o /dev/null "$B?action=si&nocache=1"
