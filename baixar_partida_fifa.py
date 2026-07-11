import json
import sys
from pathlib import Path
from urllib.parse import urljoin

import requests


BASE_CXM = "https://cxm-api.fifa.com/fifaplusweb/api/"
BASE_API = "https://api.fifa.com/api/v3/"

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
        "AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/126.0.0.0 Safari/537.36"
    ),
    "Accept": "application/json, text/plain, */*",
    "Accept-Language": "pt-BR,pt;q=0.9,en;q=0.8",
}


def baixar_json(url: str):
    print("Baixando:", url)

    resposta = requests.get(
        url,
        headers=HEADERS,
        timeout=60,
    )

    resposta.raise_for_status()
    return resposta.json()


def encontrar_endpoints(valor):
    encontrados = []

    if isinstance(valor, dict):
        for chave, item in valor.items():
            if chave in {"entryEndpoint", "endpoint"} and isinstance(item, str):
                encontrados.append(item)

            encontrados.extend(encontrar_endpoints(item))

    elif isinstance(valor, list):
        for item in valor:
            encontrados.extend(encontrar_endpoints(item))

    return encontrados


def baixar_partida(
    competition_id: str,
    season_id: str,
    stage_id: str,
    match_id: str,
):
    pagina_url = (
        f"{BASE_CXM}pages/en/match-centre/match/"
        f"{competition_id}/{season_id}/{stage_id}/{match_id}"
    )

    resultado = {
        "competition_id": competition_id,
        "season_id": season_id,
        "stage_id": stage_id,
        "match_id": match_id,
        "pagina": None,
        "secoes": {},
        "timeline": None,
        "erros": [],
    }

    try:
        pagina = baixar_json(pagina_url)
        resultado["pagina"] = pagina
    except Exception as erro:
        resultado["erros"].append({
            "tipo": "pagina",
            "url": pagina_url,
            "erro": str(erro),
        })
        return resultado

    fila = encontrar_endpoints(pagina)
    visitados = set()

    while fila:
        endpoint = fila.pop(0)

        if endpoint in visitados:
            continue

        visitados.add(endpoint)

        if endpoint.startswith("http"):
            url = endpoint
        else:
            url = urljoin(BASE_CXM, endpoint)

        try:
            dados = baixar_json(url)

            resultado["secoes"][endpoint] = dados

            novos = encontrar_endpoints(dados)

            for novo in novos:
                if novo not in visitados:
                    fila.append(novo)

        except Exception as erro:
            resultado["erros"].append({
                "tipo": "secao",
                "endpoint": endpoint,
                "url": url,
                "erro": str(erro),
            })

    timeline_url = (
        f"{BASE_API}timelines/{match_id}?language=en"
    )

    try:
        resultado["timeline"] = baixar_json(timeline_url)
    except Exception as erro:
        resultado["erros"].append({
            "tipo": "timeline",
            "url": timeline_url,
            "erro": str(erro),
        })

    return resultado


def main():
    # Alemanha x Paraguai
    competition_id = "17"
    season_id = "285023"
    stage_id = "289287"
    match_id = "400021513"

    if len(sys.argv) >= 5:
        competition_id = sys.argv[1]
        season_id = sys.argv[2]
        stage_id = sys.argv[3]
        match_id = sys.argv[4]

    dados = baixar_partida(
        competition_id,
        season_id,
        stage_id,
        match_id,
    )

    pasta = Path("dados-fifa")
    pasta.mkdir(exist_ok=True)

    arquivo = pasta / f"partida-{match_id}.json"

    arquivo.write_text(
        json.dumps(
            dados,
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )

    quantidade_eventos = len(
        (dados.get("timeline") or {}).get("Event", [])
    )

    print()
    print("Arquivo criado:", arquivo.resolve())
    print("Seções encontradas:", len(dados["secoes"]))
    print("Eventos da timeline:", quantidade_eventos)
    print("Erros:", len(dados["erros"]))


if __name__ == "__main__":
    main()
