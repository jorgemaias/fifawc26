import json
from pathlib import Path
from playwright.sync_api import sync_playwright

URLS = [
    "https://www.fifa.com/en/match-centre/match/17/285023/289287/400021513",
]

capturas = []


def tratar_resposta(response):
    url = response.url.lower()

    palavras = [
        "match",
        "event",
        "timeline",
        "fixture",
        "lineup",
    ]

    if not any(palavra in url for palavra in palavras):
        return

    content_type = response.headers.get("content-type", "")

    if "json" not in content_type:
        return

    try:
        dados = response.json()

        capturas.append({
            "url": response.url,
            "dados": dados,
        })

        print("JSON capturado:", response.url)

    except Exception:
        pass


with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)

    page = browser.new_page(
        locale="pt-BR",
        timezone_id="America/Manaus",
    )

    page.on("response", tratar_resposta)

    for url in URLS:
        print("Abrindo:", url)

        page.goto(
            url,
            wait_until="networkidle",
            timeout=120000,
        )

        page.wait_for_timeout(10000)

    browser.close()


Path("capturas-fifa.json").write_text(
    json.dumps(
        capturas,
        ensure_ascii=False,
        indent=2,
    ),
    encoding="utf-8",
)

print("Finalizado:", len(capturas), "respostas JSON capturadas")
