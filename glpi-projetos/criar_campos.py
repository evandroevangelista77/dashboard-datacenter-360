#!/usr/bin/env python3
"""Cria no GLPI 12 os campos do cadastro de Projeto (planilha "Oficial Projetos").

Idempotente: o que já existe (pelo nome/rótulo) é mantido.
- Tipos e estados de projeto (nativos)
- Bloco "Dados do projeto" do plugin Fields com os campos extras e as listas

Variáveis de ambiente: GLPI_URL (…/apirest.php), GLPI_APP_TOKEN, GLPI_USER_TOKEN
"""
import json, os, sys, urllib.request, urllib.error

URL = os.environ["GLPI_URL"].rstrip("/")
APP = os.environ["GLPI_APP_TOKEN"]
HEAD = {"App-Token": APP, "Content-Type": "application/json"}

TIPOS = ["Projeto", "Manutenção", "Atividade Controlada"]
ESTADOS = [  # nome, cor, encerra o projeto
    ("Iniciação", "#3987e5", 0), ("Planejamento", "#5598e7", 0), ("Execução", "#fab219", 0),
    ("Pendente Cliente", "#ec835a", 0), ("Pendente OAK", "#ec835a", 0),
    ("Encerramento", "#0ca30c", 0), ("Fechado", "#7b8798", 1),
]
CLIENTES_ARQ = os.path.join(os.path.dirname(__file__), "clientes.txt")
LISTAS = {
    "Ponto de Atenção": ["Gerente de Projeto", "PMO", "Comercial", "Pré-Vendas", "Compras", "Logística"],
    "Esforço": ["Baixo", "Médio", "Alto"],
    "Complexidade": ["Baixa", "Média", "Alta"],
    "Classificação": ["P1", "P2", "P3", "Atividade Controlada"],
}
CAMPOS = [  # rótulo, tipo do Fields
    ("Comercial e financeiro", "header"),
    ("Cliente", "dropdown"),
    ("Horas GP", "number"),
    ("Receita (R$)", "number"),
    ("Receita de Serviço (R$)", "number"),
    ("Margem Venda (%)", "number"),
    ("Margem EAC (%)", "number"),
    ("Manutenção e prazos", "header"),
    ("Data do Aviso de Fechamento", "date"),
    ("Prazo EAC", "date"),
    ("Previsão Conclusão GP", "date"),
    ("Possui Manutenção", "yesno"),
    ("Período Manutenção (meses)", "number"),
    ("Acompanhamento", "header"),
    ("Líder Técnico", "dropdown-User"),
    ("Status Atual do Projeto", "textarea"),
    ("Ponto de Atenção", "dropdown"),
    ("Descrição do Ponto de Atenção", "textarea"),
    ("Esforço", "dropdown"),
    ("Complexidade", "dropdown"),
    ("Classificação", "dropdown"),
]


def call(method, path, body=None, session=None):
    h = dict(HEAD)
    if session:
        h["Session-Token"] = session
    else:
        h["Authorization"] = "user_token " + os.environ["GLPI_USER_TOKEN"]
    req = urllib.request.Request(URL + path, json.dumps(body).encode() if body is not None else None, h, method=method)
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            raw = r.read()
            return json.loads(raw) if raw else None
    except urllib.error.HTTPError as e:
        raise RuntimeError(f"{method} {path}: HTTP {e.code} {e.read().decode()[:300]}")


def all_items(s, itemtype):
    return call("GET", f"/{itemtype}?range=0-9999", session=s) or []


def ensure(s, itemtype, rows, key="name"):
    """Cria os itens que faltam; devolve {valor da chave: id}."""
    have = {i[key]: i["id"] for i in all_items(s, itemtype)}
    new = [r for r in rows if r[key] not in have]
    if new:
        res = call("POST", f"/{itemtype}", {"input": new}, s)
        for r, out in zip(new, res if isinstance(res, list) else [res]):
            if not out.get("id"):
                raise RuntimeError(f"{itemtype} {r[key]}: {out}")
            have[r[key]] = out["id"]
        print(f"  {itemtype}: +{len(new)}")
    return have


def main():
    s = call("GET", "/initSession")["session_token"]
    try:
        print("Tipos de projeto");  ensure(s, "ProjectType", [{"name": t} for t in TIPOS])
        print("Estados de projeto"); ensure(s, "ProjectState", [{"name": n, "color": c, "is_finished": f} for n, c, f in ESTADOS])

        print("Bloco do Fields")
        conts = {c["label"]: c for c in all_items(s, "PluginFieldsContainer")}
        if "Dados do projeto" not in conts:
            out = call("POST", "/PluginFieldsContainer", {"input": {
                "label": "Dados do projeto", "type": "dom", "itemtypes": ["Project"],
                "is_active": 1, "entities_id": 0, "is_recursive": 1}}, s)
            cid = out["id"]; print(f"  bloco criado (id {cid})")
        else:
            cid = conts["Dados do projeto"]["id"]; print(f"  bloco já existe (id {cid})")

        print("Campos")
        existing = {f["label"]: f for f in all_items(s, "PluginFieldsField") if f["plugin_fields_containers_id"] == cid}
        for rank, (label, ftype) in enumerate(CAMPOS, start=1):
            if label in existing:
                continue
            out = call("POST", "/PluginFieldsField", {"input": {
                "label": label, "type": ftype, "plugin_fields_containers_id": cid,
                "ranking": rank, "is_active": 1, "mandatory": 0, "is_readonly": 0, "default_value": ""}}, s)
            existing[label] = {"id": out["id"]}
            print(f"  + {label} ({ftype})")

        print("Listas")
        fields = {f["label"]: f for f in all_items(s, "PluginFieldsField") if f["plugin_fields_containers_id"] == cid}
        listas = dict(LISTAS)
        if os.path.exists(CLIENTES_ARQ):
            listas["Cliente"] = [l.strip() for l in open(CLIENTES_ARQ, encoding="utf-8") if l.strip()]
        for label, values in listas.items():
            itemtype = "PluginFields" + fields[label]["name"].capitalize() + "Dropdown"
            got = ensure(s, itemtype, [{"name": v, "entities_id": 0, "is_recursive": 1} for v in values])
            print(f"  {label}: {len(got)} opções ({itemtype})")
    finally:
        call("GET", "/killSession", session=s)


if __name__ == "__main__":
    main()
