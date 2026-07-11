import json
import time
from pathlib import Path

import requests


BASE_API = "https://api.fifa.com/api/v3"

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
        "AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/126.0.0.0 Safari/537.36"
    ),
    "Accept": "application/json, text/plain, */*",
    "Accept-Language": "en-US,en;q=0.9",
}

COMPETITION_ID = "17"
SEASON_ID = "285023"

PASTA = Path("dados-fifa-completos")
PASTA.mkdir(exist_ok=True)


def baixar_json(url: str, params=None):
    resposta = requests.get(
        url,
        params=params,
        headers=HEADERS,
        timeout=90,
    )

    resposta.raise_for_status()
    return resposta.json()


def baixar_calendario():
    url = f"{BASE_API}/calendar/matches"

    params = {
        "language": "en",
        "idCompetition": COMPETITION_ID,
        "idSeason": SEASON_ID,
        "count": 400,
    }

    print("Baixando calendário completo...")

    dados = baixar_json(url, params=params)

    partidas = dados.get("Results", [])

    print("Partidas encontradas:", len(partidas))

    return partidas


def baixar_timeline(match_id: str):
    url = f"{BASE_API}/timelines/{match_id}"

    return baixar_json(
        url,
        params={"language": "en"},
    )


def main():
    partidas = baixar_calendario()

    arquivo_calendario = PASTA / "calendario-fifa.json"

    arquivo_calendario.write_text(
        json.dumps(
            partidas,
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )

    resultado = []

    total = len(partidas)

    for indice, partida in enumerate(partidas, start=1):
        match_id = str(partida.get("IdMatch", ""))

        if not match_id:
            continue

        print(f"[{indice}/{total}] Partida {match_id}")

        registro = {
            "partida": partida,
            "timeline": None,
            "erro": None,
        }

        try:
            timeline = baixar_timeline(match_id)
            registro["timeline"] = timeline

            quantidade = len(timeline.get("Event", []))
            print("  Eventos:", quantidade)

        except Exception as erro:
            registro["erro"] = str(erro)
            print("  Erro:", erro)

        resultado.append(registro)

        arquivo_individual = PASTA / f"partida-{match_id}.json"

        arquivo_individual.write_text(
            json.dumps(
                registro,
                ensure_ascii=False,
                indent=2,
            ),
            encoding="utf-8",
        )

        time.sleep(0.25)

    arquivo_final = PASTA / "todas-partidas-fifa.json"

    arquivo_final.write_text(
        json.dumps(
            {
                "quantidade": len(resultado),
                "partidas": resultado,
            },
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )

    erros = [
        item
        for item in resultado
        if item["erro"]
    ]

    print()
    print("Finalizado")
    print("Partidas:", len(resultado))
    print("Erros:", len(erros))
    print("Arquivo:", arquivo_final.resolve())


if __name__ == "__main__":
    main()
