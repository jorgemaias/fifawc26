<?php
// index.php — Copa 2026 (arquivo único: config + estatísticas + grupos + chaveamento + render + roteador)

// Erros ficam no log do servidor em produção. Use APP_DEBUG=1 no ambiente para exibi-los.
$debug = getenv('APP_DEBUG') === '1';
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
error_reporting(E_ALL);
session_start();
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

// ============================================================
// CONFIG — Constantes e funções auxiliares (cache, backup, log, validação)
// ============================================================

define('GRUPOS_FILE', __DIR__ . '/p-grupos.json');
define('CHAVEAMENTO_FILE', __DIR__ . '/p-chaveamento.json');
define('LOG_FILE', __DIR__ . '/log.json');

$GLOBALS['json_cache'] = [];

final class JsonRepository {
    private static array $cache = [];

    public static function load(string $arquivo): array {
        if (!is_file($arquivo)) return [];
        $mtime = filemtime($arquivo) ?: 0;
        $key = $arquivo . ':' . $mtime;
        if (isset(self::$cache[$key])) return self::$cache[$key];
        $conteudo = file_get_contents($arquivo);
        if ($conteudo === false || trim($conteudo) === '') return [];
        $dados = json_decode($conteudo, true);
        if (!is_array($dados)) throw new RuntimeException('JSON inválido: ' . basename($arquivo));
        return self::$cache[$key] = $dados;
    }

    public static function save(string $arquivo, array $dados): void {
        $dir = dirname($arquivo);
        if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('Diretório sem permissão de escrita.');
        $json = json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new RuntimeException('Falha ao serializar JSON.');
        $temp = tempnam($dir, '.tmp_copa_');
        if ($temp === false) throw new RuntimeException('Falha ao criar arquivo temporário.');
        $fp = fopen($temp, 'wb');
        if (!$fp) throw new RuntimeException('Falha ao abrir arquivo temporário.');
        try {
            if (!flock($fp, LOCK_EX)) throw new RuntimeException('Falha ao bloquear gravação.');
            if (fwrite($fp, $json) === false) throw new RuntimeException('Falha ao gravar dados.');
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally { fclose($fp); }
        if (is_file($arquivo) && filesize($arquivo) > 0) @copy($arquivo, $arquivo . '.bak');
        if (!rename($temp, $arquivo)) { @unlink($temp); throw new RuntimeException('Falha ao substituir arquivo de dados.'); }
        self::$cache = [];
        clearstatcache(true, $arquivo);
    }
}

function carregarJson(string $arquivo): array { return JsonRepository::load($arquivo); }
function salvarJson(string $arquivo, array $dados): void { JsonRepository::save($arquivo, $dados); }

function csrfToken(): string { return $_SESSION['csrf_token']; }
function validarCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        throw new RuntimeException('Sessão expirada ou solicitação inválida. Recarregue a página.');
    }
}
function textoSeguro(mixed $valor, int $max = 120): string {
    $v = trim(strip_tags((string)$valor));
    return mb_substr($v, 0, $max);
}
function dataValida(string $data): bool { $d = DateTime::createFromFormat('Y-m-d', $data); return $d && $d->format('Y-m-d') === $data; }
function horaValida(string $hora): bool { return (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $hora); }

function carregarTodasPartidas(): array {
    return array_merge(carregarJson(GRUPOS_FILE), carregarJson(CHAVEAMENTO_FILE));
}

function getEstadiosUnicos(array $partidas): array {
    $estadios = [];
    foreach ($partidas as $p) $estadios[$p['estadio']] = $p['cidade'] ?? '';
    return $estadios;
}

function getTodosTimes(): array {
    $partidas = carregarTodasPartidas();
    $times = [];
    foreach ($partidas as $p) {
        if (!empty($p['time1']) && $p['time1'] !== 'A definir') $times[] = $p['time1'];
        if (!empty($p['time2']) && $p['time2'] !== 'A definir') $times[] = $p['time2'];
    }
    $times = array_unique($times);
    sort($times);
    return $times;
}

function getCountryCode(string $nome): string {
    $map = [
        'México' => 'mx', 'África do Sul' => 'za', 'Coreia do Sul' => 'kr', 'República Tcheca' => 'cz',
        'Canadá' => 'ca', 'Bósnia e Herzegovina' => 'ba', 'Qatar' => 'qa', 'Suíça' => 'ch',
        'Brasil' => 'br', 'Marrocos' => 'ma', 'Haiti' => 'ht', 'Escócia' => 'gb-sct',
        'Estados Unidos' => 'us', 'Paraguai' => 'py', 'Austrália' => 'au', 'Turquia' => 'tr',
        'Alemanha' => 'de', 'Curaçao' => 'cw', 'Costa do Marfim' => 'ci', 'Equador' => 'ec',
        'Holanda' => 'nl', 'Japão' => 'jp', 'Suécia' => 'se', 'Tunísia' => 'tn',
        'Bélgica' => 'be', 'Egito' => 'eg', 'Irã' => 'ir', 'Nova Zelândia' => 'nz',
        'Espanha' => 'es', 'Cabo Verde' => 'cv', 'Arábia Saudita' => 'sa', 'Uruguai' => 'uy',
        'França' => 'fr', 'Senegal' => 'sn', 'Iraque' => 'iq', 'Noruega' => 'no',
        'Argentina' => 'ar', 'Argélia' => 'dz', 'Áustria' => 'at', 'Jordânia' => 'jo',
        'Portugal' => 'pt', 'RD Congo' => 'cd', 'Uzbequistão' => 'uz', 'Colômbia' => 'co',
        'Inglaterra' => 'gb-eng', 'Croácia' => 'hr', 'Gana' => 'gh', 'Panamá' => 'pa',
    ];
    return $map[$nome] ?? strtolower(substr($nome, 0, 2));
}

function bandeiraSvg(string $nome): string {
    $code = getCountryCode($nome);
    return "<img src='https://flagcdn.com/{$code}.svg' width='20' height='15'
                 style='display:inline-block;vertical-align:middle;margin:0 4px;border-radius:2px;'
                 alt='{$nome}' loading='lazy'>";
}

function normalizarMinuto(string $minuto): string {
    $minuto = trim($minuto);
    if (empty($minuto)) return '0';
    if (preg_match('/^\d+(\+\d+)?$/', $minuto)) return $minuto;
    if (preg_match('/(\d+)\s*\+\s*(\d+)/', $minuto, $m)) return $m[1] . '+' . $m[2];
    return preg_replace('/[^0-9]/', '', $minuto);
}

function compararMinutos(string $a, string $b): int {
    return extrairTempoTotal($b) <=> extrairTempoTotal($a);
}

function extrairTempoTotal(string $minuto): int {
    if (strpos($minuto, '+') !== false) {
        $partes = explode('+', $minuto);
        return (int)$partes[0] * 100 + (int)($partes[1] ?? 0);
    }
    return (int)$minuto * 100;
}

function formatarMinuto(string $minuto): string {
    $minuto = trim($minuto);
    if (empty($minuto)) return "0'";
    if (strpos($minuto, '+') !== false) {
        $partes = explode('+', $minuto);
        return '<span class="minuto-acrescimo">' . intval($partes[0]) . '<span class="acrescimo">+</span>' . intval($partes[1]) . "'</span>";
    }
    return intval($minuto) . "'";
}

function eventosPorTime(array $eventos, string $time): array {
    $filtrado = array_filter($eventos, fn($e) => ($e['time'] ?? 'casa') === $time);
    usort($filtrado, fn($a, $b) => compararMinutos($b['minuto'], $a['minuto']));
    return $filtrado;
}

function coletarNomesJogadores(): array {
    $partidas = carregarTodasPartidas();
    $indice = [];
    foreach ($partidas as $p) {
        foreach ($p['eventos'] ?? [] as $ev) {
            if (!empty($ev['jogador'])) {
                $indice[strtolower($ev['jogador'])] = $ev['jogador'];
            }
        }
        foreach ($p['cartoes'] ?? [] as $c) {
            if (!empty($c['jogador'])) {
                $indice[strtolower($c['jogador'])] = $c['jogador'];
            }
        }
    }
    ksort($indice);
    return array_values($indice);
}

function registrarLog(string $acao, array $dados): void {
    $log = carregarJson(LOG_FILE);
    $log[] = [
        'data' => date('Y-m-d H:i:s'),
        'acao' => $acao,
        'dados' => $dados
    ];
    if (count($log) > 100) {
        $log = array_slice($log, -100);
    }
    salvarJson(LOG_FILE, $log);
}

function validarIntegridade(): array {
    $erros = [];
    $partidas = carregarTodasPartidas();

    if (count($partidas) !== 104) {
        $erros[] = "Total de partidas: " . count($partidas) . " (esperado: 104)";
    }

    $grupos = array_filter($partidas, fn($p) => ($p['fase'] ?? '') === 'grupos');
    if (count($grupos) !== 72) {
        $erros[] = "Partidas de grupos: " . count($grupos) . " (esperado: 72)";
    }

    $eliminatorias = array_filter($partidas, fn($p) => ($p['fase'] ?? '') !== 'grupos');
    if (count($eliminatorias) !== 32) {
        $erros[] = "Partidas eliminatórias: " . count($eliminatorias) . " (esperado: 32)";
    }

    $ids = [];
    foreach ($partidas as $p) {
        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) $erros[] = 'Partida sem ID válido.';
        elseif (isset($ids[$id])) $erros[] = "ID duplicado: {$id}";
        $ids[$id] = true;
        if (($p['time1'] ?? '') === ($p['time2'] ?? '') && ($p['time1'] ?? '') !== 'A definir') $erros[] = "Partida #{$id} possui a mesma seleção nos dois lados.";
        if (($p['status'] ?? '') === 'finalizada' && (($p['gols1'] ?? null) === null || ($p['gols2'] ?? null) === null)) $erros[] = "Partida #{$id} finalizada sem placar.";
    }

    return array_values(array_unique($erros));
}

function validarGols(mixed $valor): ?int {
    $valor = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 20]]);
    return $valor !== false ? $valor : null;
}

// No mata-mata, um empate no placar só é decidido nos pênaltis (o placar normal
// nunca resolve sozinho quem avança). Esta função é a ÚNICA fonte de verdade sobre
// quem venceu uma partida — usada tanto para destacar o vencedor no card quanto
// para propagar o avanço no chaveamento.
function resultadoPartida(array $p): array {
    $v1 = false; $v2 = false; $decididoPorPenaltis = false;

    if ($p['gols1'] !== null && $p['gols2'] !== null) {
        if ($p['gols1'] > $p['gols2']) {
            $v1 = true;
        } elseif ($p['gols2'] > $p['gols1']) {
            $v2 = true;
        } elseif (
            isset($p['penaltis1'], $p['penaltis2']) &&
            $p['penaltis1'] !== null && $p['penaltis2'] !== null &&
            $p['penaltis1'] !== $p['penaltis2']
        ) {
            $decididoPorPenaltis = true;
            if ($p['penaltis1'] > $p['penaltis2']) { $v1 = true; } else { $v2 = true; }
        }
    }

    return ['v1' => $v1, 'v2' => $v2, 'penaltis' => $decididoPorPenaltis];
}

function getNomeFase(string $fase): string {
    $mapa = [
        'grupos' => 'Fase de Grupos',
        '16avos' => 'Rodada de 32',
        'oitavas' => 'Oitavas de Final',
        'quartas' => 'Quartas de Final',
        'semi' => 'Semifinal',
        'final' => 'Final',
        'terceiro' => 'Disputa pelo 3º Lugar'
    ];
    return $mapa[$fase] ?? $fase;
}

// ============================================================
// ESTATÍSTICAS — Cálculos de artilheiros e agregados
// ============================================================

function calcularArtilheiros(): array {
    $partidas = carregarTodasPartidas();
    $artilheiros = [];

    foreach ($partidas as $p) {
        foreach ($p['eventos'] ?? [] as $ev) {
            if (!empty($ev['gol_contra'])) continue;

            $nome = $ev['jogador'] ?? '';
            $selecao = $ev['selecao'] ?? '';

            if (empty($selecao)) {
                $timeGol = $ev['time'] ?? 'casa';
                $selecao = $timeGol === 'casa' ? ($p['time1'] ?? '') : ($p['time2'] ?? '');
            }

            if (empty($nome)) continue;

            if (!isset($artilheiros[$nome])) {
                $artilheiros[$nome] = [
                    'selecao' => $selecao,
                    'gols' => 0
                ];
            }

            if (empty($artilheiros[$nome]['selecao']) && !empty($selecao)) {
                $artilheiros[$nome]['selecao'] = $selecao;
            }

            $artilheiros[$nome]['gols']++;
        }
    }

    uasort($artilheiros, fn($a, $b) => $b['gols'] <=> $a['gols']);

    return $artilheiros;
}

function calcularGolsPorSelecao(array $partidas): array {
    $golsPorSelecao = [];

    foreach ($partidas as $p) {
        if ($p['gols1'] === null || $p['gols2'] === null) continue;

        $time1 = $p['time1'];
        $time2 = $p['time2'];

        if (!isset($golsPorSelecao[$time1])) {
            $golsPorSelecao[$time1] = ['gp' => 0, 'gc' => 0, 'jogos' => 0];
        }
        if (!isset($golsPorSelecao[$time2])) {
            $golsPorSelecao[$time2] = ['gp' => 0, 'gc' => 0, 'jogos' => 0];
        }

        $golsPorSelecao[$time1]['gp'] += $p['gols1'];
        $golsPorSelecao[$time1]['gc'] += $p['gols2'];
        $golsPorSelecao[$time1]['jogos']++;

        $golsPorSelecao[$time2]['gp'] += $p['gols2'];
        $golsPorSelecao[$time2]['gc'] += $p['gols1'];
        $golsPorSelecao[$time2]['jogos']++;
    }

    foreach ($golsPorSelecao as &$s) {
        $s['sg'] = $s['gp'] - $s['gc'];
        $s['media'] = $s['jogos'] > 0 ? round($s['gp'] / $s['jogos'], 2) : 0;
    }
    unset($s);

    uasort($golsPorSelecao, fn($a, $b) => $b['gp'] <=> $a['gp']);

    return $golsPorSelecao;
}

function calcularEstatisticasGerais(array $partidas): array {
    $totalGols = 0;
    $totalFinalizadas = 0;
    $totalJogos = count($partidas);
    $maiorPlacar = ['gols' => 0, 'partida' => null];
    $jogosComGols = 0;

    foreach ($partidas as $p) {
        if ($p['gols1'] !== null && $p['gols2'] !== null) {
            $totalFinalizadas++;
            $golsPartida = $p['gols1'] + $p['gols2'];
            $totalGols += $golsPartida;

            if ($golsPartida > 0) $jogosComGols++;

            if ($golsPartida > $maiorPlacar['gols']) {
                $maiorPlacar['gols'] = $golsPartida;
                $maiorPlacar['partida'] = $p;
            }
        }
    }

    return [
        'total_gols' => $totalGols,
        'total_finalizadas' => $totalFinalizadas,
        'total_jogos' => $totalJogos,
        'media_gols' => $totalFinalizadas > 0 ? round($totalGols / $totalFinalizadas, 2) : 0,
        'jogos_com_gols' => $jogosComGols,
        'maior_placar' => $maiorPlacar,
        'aproveitamento' => $totalJogos > 0 ? round(($totalFinalizadas / $totalJogos) * 100, 1) : 0
    ];
}


function calcularDashboard(array $partidas): array {
    $hoje = date('Y-m-d'); $gols=0; $finalizadas=0; $cartoes=0; $gc=0; $hojeCount=0; $pendentes=0;
    foreach ($partidas as $p) {
        if (($p['data'] ?? '') === $hoje) $hojeCount++;
        if (($p['status'] ?? '') === 'finalizada' && $p['gols1'] !== null && $p['gols2'] !== null) { $finalizadas++; $gols += $p['gols1'] + $p['gols2']; }
        else $pendentes++;
        $cartoes += count($p['cartoes'] ?? []);
        foreach ($p['eventos'] ?? [] as $e) if (!empty($e['gol_contra'])) $gc++;
    }
    return compact('gols','finalizadas','cartoes','gc','hojeCount','pendentes') + ['total'=>count($partidas),'media'=>$finalizadas ? round($gols/$finalizadas,2):0];
}

function calcularEstatisticasAvancadas(array $partidas): array {
    $sel=[]; $est=[]; $fase=[]; $intervalos=['0-15'=>0,'16-30'=>0,'31-45+'=>0,'46-60'=>0,'61-75'=>0,'76-90+'=>0];
    foreach ($partidas as $p) {
        if ($p['gols1'] === null || $p['gols2'] === null) continue;
        $faseNome=getNomeFase($p['fase'] ?? ''); $fase[$faseNome]=($fase[$faseNome]??0)+$p['gols1']+$p['gols2'];
        $estadio=$p['estadio']??'Não informado'; if(!isset($est[$estadio]))$est[$estadio]=['jogos'=>0,'gols'=>0]; $est[$estadio]['jogos']++;$est[$estadio]['gols']+=$p['gols1']+$p['gols2'];
        foreach ([[$p['time1'],$p['gols1'],$p['gols2']],[$p['time2'],$p['gols2'],$p['gols1']]] as [$t,$gp,$gc]) {
            if(!isset($sel[$t]))$sel[$t]=['j'=>0,'v'=>0,'e'=>0,'d'=>0,'gp'=>0,'gc'=>0,'pts'=>0,'seq'=>0,'invicta'=>0,'max_v'=>0,'max_inv'=>0,'clean'=>0];
            $s=&$sel[$t];$s['j']++;$s['gp']+=$gp;$s['gc']+=$gc;if($gc===0)$s['clean']++;
            if($gp>$gc){$s['v']++;$s['pts']+=3;$s['seq']++;$s['invicta']++;}elseif($gp===$gc){$s['e']++;$s['pts']++;$s['seq']=0;$s['invicta']++;}else{$s['d']++;$s['seq']=0;$s['invicta']=0;}
            $s['max_v']=max($s['max_v'],$s['seq']);$s['max_inv']=max($s['max_inv'],$s['invicta']);unset($s);
        }
        foreach($p['eventos']??[] as $ev){$m=(int)explode('+',(string)($ev['minuto']??0))[0];$k=$m<=15?'0-15':($m<=30?'16-30':($m<=45?'31-45+':($m<=60?'46-60':($m<=75?'61-75':'76-90+'))));$intervalos[$k]++;}
    }
    foreach($sel as &$s){$s['sg']=$s['gp']-$s['gc'];$s['aproveitamento']=$s['j']?round(($s['pts']/($s['j']*3))*100,1):0;$s['media']=$s['j']?round($s['gp']/$s['j'],2):0;}unset($s);
    uasort($sel,fn($a,$b)=>[$b['pts'],$b['sg'],$b['gp']]<=>[$a['pts'],$a['sg'],$a['gp']]);
    uasort($est,fn($a,$b)=>$b['gols']<=>$a['gols']); arsort($fase);
    return ['selecoes'=>$sel,'estadios'=>$est,'fases'=>$fase,'intervalos'=>$intervalos];
}

function historicoSelecao(array $partidas, string $selecao): array {
    $jogos=[];$tot=['j'=>0,'v'=>0,'e'=>0,'d'=>0,'gp'=>0,'gc'=>0,'pts'=>0,'clean'=>0];
    foreach($partidas as $p){if(($p['time1']??'')!==$selecao&&($p['time2']??'')!==$selecao)continue;$casa=$p['time1']===$selecao;$gp=$casa?$p['gols1']:$p['gols2'];$gc=$casa?$p['gols2']:$p['gols1'];$r='Agendada';if($gp!==null&&$gc!==null){$tot['j']++;$tot['gp']+=$gp;$tot['gc']+=$gc;if($gc===0)$tot['clean']++;if($gp>$gc){$tot['v']++;$tot['pts']+=3;$r='Vitória';}elseif($gp===$gc){$tot['e']++;$tot['pts']++;$r='Empate';}else{$tot['d']++;$r='Derrota';}}$jogos[]=$p+['adversario'=>$casa?$p['time2']:$p['time1'],'gp_selecao'=>$gp,'gc_selecao'=>$gc,'resultado_selecao'=>$r];}
    usort($jogos,fn($a,$b)=>strcmp(($a['data']??'').' '.($a['horario']??''),($b['data']??'').' '.($b['horario']??'')));
    return ['totais'=>$tot,'jogos'=>$jogos];
}

function renderDashboard(array $partidas): string {
    $d=calcularDashboard($partidas);$cards=[['ri-calendar-2-line',$d['total'],'Partidas'],['ri-checkbox-circle-line',$d['finalizadas'],'Finalizadas'],['ri-football-fill',$d['gols'],'Gols'],['ri-speed-line',number_format($d['media'],2,',','.'),'Gols por jogo'],['ri-error-warning-line',$d['gc'],'Gols contra'],['ri-rectangle-line',$d['cartoes'],'Cartões'],['ri-calendar-event-line',$d['hojeCount'],'Jogos hoje'],['ri-time-line',$d['pendentes'],'Pendentes']];ob_start();?><section class="mb-8"><div class="flex items-end justify-between gap-4 mb-4"><div><p class="text-[.65rem] uppercase tracking-[.22em] text-[#c9b896]">Visão geral</p><h2 class="font-titulo font-bold text-2xl">Dashboard da Copa</h2></div><span class="text-xs text-[#f8f7f4]/40 font-mono"><?=date('d/m/Y H:i')?></span></div><div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-3"><?php foreach($cards as [$ic,$v,$l]):?><div class="metric-card"><i class="<?=$ic?>"></i><strong><?=$v?></strong><span><?=$l?></span></div><?php endforeach;?></div></section><?php return ob_get_clean();
}

function renderTimeline(array $partidas): string { $dias=[];foreach($partidas as $p)$dias[$p['data']][]=$p;ksort($dias);ob_start();?><div class="mb-6"><h2 class="font-titulo font-bold text-xl"><i class="ri-timeline-view text-[#c9b896]"></i> Timeline visual da Copa</h2><p class="text-sm text-[#f8f7f4]/50">Cronologia completa, da abertura à final.</p></div><div class="timeline"><div class="timeline-line"></div><?php foreach($dias as $data=>$jogos):$fin=count(array_filter($jogos,fn($j)=>($j['status']??'')==='finalizada'));?><article class="timeline-day"><div class="timeline-dot <?=$fin===count($jogos)?'done':''?>"></div><div class="card p-4"><div class="flex flex-wrap justify-between gap-2 mb-3"><h3 class="font-titulo font-semibold"><?=date('d/m/Y',strtotime($data))?></h3><span class="text-xs text-[#c9b896] font-mono"><?=$fin?>/<?=count($jogos)?> finalizadas</span></div><div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-2"><?php foreach($jogos as $j):?><button class="timeline-match" onclick="abrirModalEdicao(<?=$j['id']?>)"><span class="text-[.65rem] text-[#c9b896]"><?=htmlspecialchars(getNomeFase($j['fase']??''))?> · <?=$j['horario']?></span><span><?=htmlspecialchars($j['time1'])?> <b><?=$j['gols1']??'·'?> × <?=$j['gols2']??'·'?></b> <?=htmlspecialchars($j['time2'])?></span></button><?php endforeach;?></div></div></article><?php endforeach;?></div><?php return ob_get_clean(); }

function renderSelecoes(array $partidas,string $selecionada): string {$times=getTodosTimes();if(!in_array($selecionada,$times,true))$selecionada=$times[0]??'';$h=historicoSelecao($partidas,$selecionada);$t=$h['totais'];ob_start();?><div class="mb-6 flex flex-col md:flex-row md:items-end justify-between gap-4"><div><h2 class="font-titulo font-bold text-xl"><i class="ri-shield-star-line text-[#c9b896]"></i> Histórico por seleção</h2><p class="text-sm text-[#f8f7f4]/50">Campanha completa, resultados e desempenho.</p></div><form method="get"><input type="hidden" name="aba" value="selecoes"><select name="selecao" onchange="this.form.submit()" class="input-ui min-w-64"><?php foreach($times as $tm):?><option <?=$tm===$selecionada?'selected':''?>><?=htmlspecialchars($tm)?></option><?php endforeach;?></select></form></div><div class="card p-5 mb-6"><div class="flex items-center gap-3 mb-5"><?=bandeiraSvg($selecionada)?><h3 class="font-titulo font-bold text-2xl"><?=htmlspecialchars($selecionada)?></h3></div><div class="grid grid-cols-4 md:grid-cols-8 gap-3"><?php foreach(['j'=>'J','v'=>'V','e'=>'E','d'=>'D','gp'=>'GP','gc'=>'GC','pts'=>'PTS','clean'=>'SG'] as $k=>$l):?><div class="stat-mini"><strong><?=$t[$k]?></strong><span><?=$l?></span></div><?php endforeach;?></div></div><div class="space-y-3"><?php foreach($h['jogos'] as $j):?><button onclick="abrirModalEdicao(<?=$j['id']?>)" class="card match-history"><span class="text-xs text-[#f8f7f4]/45"><?=date('d/m/Y',strtotime($j['data']))?> · <?=htmlspecialchars(getNomeFase($j['fase']))?></span><span class="flex items-center gap-2 flex-1"><?=bandeiraSvg($j['adversario'])?> <?=htmlspecialchars($j['adversario'])?></span><b class="font-mono"><?=$j['gp_selecao']??'·'?> × <?=$j['gc_selecao']??'·'?></b><span class="result-pill result-<?=strtolower(str_replace(['ó','í'],'i',$j['resultado_selecao']))?>"><?=$j['resultado_selecao']?></span></button><?php endforeach;?></div><?php return ob_get_clean();}

// ============================================================
// GRUPOS — Classificação + salvamento (validação e log)
// ============================================================

function calcularClassificacao(array $partidas, string $grupo): array {
    $times = [];

    foreach ($partidas as $p) {
        if (($p['grupo'] ?? '') !== $grupo) continue;
        if ($p['gols1'] === null || $p['gols2'] === null) continue;

        $t1 = $p['time1'];
        $t2 = $p['time2'];

        foreach ([$t1, $t2] as $t) {
            if (!isset($times[$t])) {
                $times[$t] = [
                    'pts' => 0,
                    'j' => 0,
                    'v' => 0,
                    'e' => 0,
                    'd' => 0,
                    'gm' => 0,
                    'gs' => 0,
                    'sg' => 0
                ];
            }
        }

        $times[$t1]['j']++;
        $times[$t2]['j']++;
        $times[$t1]['gm'] += $p['gols1'];
        $times[$t1]['gs'] += $p['gols2'];
        $times[$t2]['gm'] += $p['gols2'];
        $times[$t2]['gs'] += $p['gols1'];

        if ($p['gols1'] > $p['gols2']) {
            $times[$t1]['pts'] += 3;
            $times[$t1]['v']++;
            $times[$t2]['d']++;
        } elseif ($p['gols1'] < $p['gols2']) {
            $times[$t2]['pts'] += 3;
            $times[$t2]['v']++;
            $times[$t1]['d']++;
        } else {
            $times[$t1]['pts'] += 1;
            $times[$t2]['pts'] += 1;
            $times[$t1]['e']++;
            $times[$t2]['e']++;
        }
    }

    foreach ($times as &$t) {
        $t['sg'] = $t['gm'] - $t['gs'];
    }
    unset($t);

    uasort($times, function($a, $b) {
        if ($b['pts'] !== $a['pts']) return $b['pts'] <=> $a['pts'];
        if ($b['sg'] !== $a['sg']) return $b['sg'] <=> $a['sg'];
        return $b['gm'] <=> $a['gm'];
    });

    return $times;
}

function processarSalvamento(): ?string {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['salvar_partida'])) {
        return null;
    }
    validarCsrf();

    $partidas = carregarTodasPartidas();
    $pid = (int)$_POST['partida_id'];

    $gols1 = validarGols($_POST['gols1'] ?? '');
    $gols2 = validarGols($_POST['gols2'] ?? '');

    $ehGrupo = false;
    $partidaOriginal = null;
    foreach ($partidas as $p) {
        if ($p['id'] === $pid) {
            $partidaOriginal = $p;
            if (($p['fase'] ?? '') === 'grupos') $ehGrupo = true;
            break;
        }
    }

    if (!$partidaOriginal) return null;

    foreach ($partidas as &$p) {
        if ($p['id'] !== $pid) continue;

        $time1 = textoSeguro($_POST['time1'] ?? $p['time1'], 80);
        $time2 = textoSeguro($_POST['time2'] ?? $p['time2'], 80);
        $timesPermitidos = getTodosTimes();
        $p['time1'] = in_array($time1, $timesPermitidos, true) ? $time1 : $p['time1'];
        $p['time2'] = in_array($time2, $timesPermitidos, true) ? $time2 : $p['time2'];
        $p['gols1']   = $gols1;
        $p['gols2']   = $gols2;
        $data = textoSeguro($_POST['data'] ?? '', 10); $hora = textoSeguro($_POST['horario'] ?? '', 5);
        if (dataValida($data)) $p['data'] = $data;
        if (horaValida($hora)) $p['horario'] = $hora;
        $estadiosPermitidos = getEstadiosUnicos($partidas);
        $estadio = textoSeguro($_POST['estadio'] ?? $p['estadio']);
        if (isset($estadiosPermitidos[$estadio])) { $p['estadio'] = $estadio; $p['cidade'] = $estadiosPermitidos[$estadio]; }
        $p['status']  = ($gols1 !== null && $gols2 !== null) ? 'finalizada' : ($_POST['status'] ?? 'agendada');
        if (($p['fase'] ?? '') === '16avos') {
            $p['manual'] = isset($_POST['manual_times']);
        }
        if (($p['fase'] ?? '') !== 'grupos') {
            $p['penaltis1'] = validarGols($_POST['penaltis1'] ?? '');
            $p['penaltis2'] = validarGols($_POST['penaltis2'] ?? '');
        }

        $eventos = [];
        $jogadoresArr = $_POST['jogador'] ?? [];
        $minutosArr   = $_POST['minuto'] ?? [];
        $timesArr     = $_POST['time_evento'] ?? [];
        $selecoesArr  = $_POST['selecao_jogador'] ?? [];

        for ($i = 0; $i < count($jogadoresArr); $i++) {
            if (!empty(trim($jogadoresArr[$i]))) {
                $nomeJogador = textoSeguro($jogadoresArr[$i], 100);
                $timeGol = $timesArr[$i] ?? 'casa';
                $selecaoJogador = textoSeguro($selecoesArr[$i] ?? '', 80);
                $ehGolContra = (bool) preg_match('/\(gc\)$/i', $nomeJogador);
                $nomeLimpo = preg_replace('/\s*\(gc\)\s*$/i', '', $nomeJogador);

                if (empty($selecaoJogador)) {
                    $selecaoJogador = $timeGol === 'casa' ? $p['time1'] : $p['time2'];
                }

                $eventos[] = [
                    'jogador' => $nomeLimpo,
                    'minuto'  => normalizarMinuto($minutosArr[$i] ?? ''),
                    'time'    => $timeGol,
                    'gol_contra' => $ehGolContra,
                    'selecao' => $selecaoJogador
                ];
            }
        }
        usort($eventos, fn($a, $b) => compararMinutos($b['minuto'], $a['minuto']));
        $p['eventos'] = $eventos;

        $cartoes = [];
        $jogCart = $_POST['jogador_cartao'] ?? [];
        $minCart = $_POST['minuto_cartao'] ?? [];
        $tipoCart = $_POST['tipo_cartao'] ?? [];
        $timeCart = $_POST['time_cartao'] ?? [];
        $selecoesCart = $_POST['selecao_cartao'] ?? [];

        for ($i = 0; $i < count($jogCart); $i++) {
            if (!empty(trim($jogCart[$i]))) {
                $nomeJogador = textoSeguro($jogCart[$i], 100);
                $selecaoJogador = textoSeguro($selecoesCart[$i] ?? '', 80);
                if (empty($selecaoJogador)) {
                    $selecaoJogador = ($timeCart[$i] ?? 'casa') === 'casa' ? $p['time1'] : $p['time2'];
                }
                $cartoes[] = [
                    'jogador' => $nomeJogador,
                    'minuto'  => normalizarMinuto($minCart[$i] ?? ''),
                    'tipo'    => $tipoCart[$i] ?? 'amarelo',
                    'time'    => $timeCart[$i] ?? 'casa',
                    'selecao' => $selecaoJogador
                ];
            }
        }
        usort($cartoes, fn($a, $b) => compararMinutos($b['minuto'], $a['minuto']));
        $p['cartoes'] = $cartoes;

        registrarLog('editar_partida', [
            'id' => $pid,
            'time1' => $p['time1'],
            'time2' => $p['time2'],
            'placar' => ($p['gols1'] ?? '?') . ' × ' . ($p['gols2'] ?? '?')
        ]);
        break;
    }
    unset($p);

    if ($ehGrupo) {
        $gruposPartidas = array_filter($partidas, fn($p) => ($p['fase'] ?? '') === 'grupos');
        salvarJson(GRUPOS_FILE, array_values($gruposPartidas));
    }
    $chaveamentoPartidas = array_filter($partidas, fn($p) => ($p['fase'] ?? '') !== 'grupos');
    salvarJson(CHAVEAMENTO_FILE, array_values($chaveamentoPartidas));

    // Recalcula o chaveamento sempre — tanto ao salvar jogos de grupos (define quem entra
    // na Rodada de 32) quanto ao salvar jogos do mata-mata (avança vencedores para a fase seguinte).
    atualizarChaveamento();
    registrarLog('atualizar_chaveamento', ['motivo' => "Partida #$pid salva"]);

    $aba = $_POST['aba'] ?? 'grupos';
    $grupo = $_POST['grupo'] ?? 'A';
    return "?aba=$aba&grupo=$grupo&msg=ok";
}

// ============================================================
// CHAVEAMENTO — Atualização do mata-mata FIFA 2026
// ============================================================

function atualizarChaveamento(): void {
    $gruposPartidas = carregarJson(GRUPOS_FILE);
    $chaveamento = carregarJson(CHAVEAMENTO_FILE);
    $grupos = range('A', 'L');
    $lideres = [];
    $vices = [];
    $terceiros = [];

    foreach ($grupos as $g) {
        $class = calcularClassificacao($gruposPartidas, $g);
        $keys = array_keys($class);
        if (count($keys) >= 2) {
            $lideres[$g] = $keys[0];
            $vices[$g] = $keys[1];
        }
        if (count($keys) >= 3) {
            $terceiros[$g] = [
                'time' => $keys[2],
                'pts' => $class[$keys[2]]['pts'],
                'sg' => $class[$keys[2]]['sg'],
                'gm' => $class[$keys[2]]['gm']
            ];
        }
    }

    uasort($terceiros, function($a, $b) {
        if ($b['pts'] !== $a['pts']) return $b['pts'] <=> $a['pts'];
        if ($b['sg'] !== $a['sg']) return $b['sg'] <=> $a['sg'];
        return $b['gm'] <=> $a['gm'];
    });

    $melhoresTerc = array_slice(array_keys($terceiros), 0, 8);

    $mapa16 = [
        73 => ['1E', '3A'],
        74 => ['1I', '3B'],
        75 => ['2A', '2B'],
        76 => ['1F', '2C'],
        77 => ['2K', '2L'],
        78 => ['1H', '2J'],
        79 => ['1D', '3C'],
        80 => ['1G', '3D'],
        81 => ['1C', '2F'],
        82 => ['2E', '2I'],
        83 => ['1A', '3E'],
        84 => ['1L', '3F'],
        85 => ['1J', '2H'],
        86 => ['2D', '2G'],
        87 => ['1B', '3G'],
        88 => ['1K', '3H'],
    ];

    foreach ($chaveamento as &$p) {
        if ($p['fase'] !== '16avos') continue;
        if (!isset($mapa16[$p['id']])) continue;
        if (!empty($p['manual'])) continue; // seleção travada manualmente: não recalcular pelos grupos

        [$a, $b] = $mapa16[$p['id']];
        $p['time1'] = resolverTime($a, $lideres, $vices, $terceiros, $melhoresTerc);
        $p['time2'] = resolverTime($b, $lideres, $vices, $terceiros, $melhoresTerc);
    }
    unset($p);

    $vencedores = [];
    foreach ($chaveamento as $p) {
        if (in_array($p['fase'], ['16avos', 'oitavas', 'quartas', 'semi']) && $p['status'] === 'finalizada') {
            $res = resultadoPartida($p);
            if ($res['v1']) {
                $vencedores['M' . $p['id']] = $p['time1'];
            } elseif ($res['v2']) {
                $vencedores['M' . $p['id']] = $p['time2'];
            }
            // Se nenhum dos dois venceu, o jogo empatou e os pênaltis ainda não
            // foram lançados — não promovemos ninguém até isso ser corrigido.
        }
    }

    $mapaOitavas = [
        89 => ['M73', 'M74'],
        90 => ['M75', 'M76'],
        91 => ['M77', 'M78'],
        92 => ['M79', 'M80'],
        93 => ['M81', 'M82'],
        94 => ['M83', 'M84'],
        95 => ['M85', 'M86'],
        96 => ['M87', 'M88'],
    ];

    $mapaQuartas = [
        97 => ['M89', 'M90'],
        98 => ['M91', 'M92'],
        99 => ['M93', 'M94'],
        100 => ['M95', 'M96'],
    ];

    $mapaSemi = [
        101 => ['M97', 'M98'],
        102 => ['M99', 'M100'],
    ];

    $mapaFinal = [
        104 => ['M101', 'M102'],
    ];

    $mapaTerceiro = [
        103 => ['M101', 'M102'],
    ];

    foreach ($chaveamento as &$p) {
        if ($p['fase'] === 'oitavas' && isset($mapaOitavas[$p['id']])) {
            [$ref1, $ref2] = $mapaOitavas[$p['id']];
            $p['time1'] = $vencedores[$ref1] ?? 'A definir';
            $p['time2'] = $vencedores[$ref2] ?? 'A definir';
        }
        if ($p['fase'] === 'quartas' && isset($mapaQuartas[$p['id']])) {
            [$ref1, $ref2] = $mapaQuartas[$p['id']];
            $p['time1'] = $vencedores[$ref1] ?? 'A definir';
            $p['time2'] = $vencedores[$ref2] ?? 'A definir';
        }
        if ($p['fase'] === 'semi' && isset($mapaSemi[$p['id']])) {
            [$ref1, $ref2] = $mapaSemi[$p['id']];
            $p['time1'] = $vencedores[$ref1] ?? 'A definir';
            $p['time2'] = $vencedores[$ref2] ?? 'A definir';
        }
        if ($p['fase'] === 'final' && isset($mapaFinal[$p['id']])) {
            [$ref1, $ref2] = $mapaFinal[$p['id']];
            $p['time1'] = $vencedores[$ref1] ?? 'A definir';
            $p['time2'] = $vencedores[$ref2] ?? 'A definir';
        }
        if ($p['fase'] === 'terceiro' && isset($mapaTerceiro[$p['id']])) {
            [$ref1, $ref2] = $mapaTerceiro[$p['id']];
            $perdedor1 = 'A definir';
            $perdedor2 = 'A definir';
            foreach ($chaveamento as $semi) {
                if ($semi['id'] === 101 && $semi['status'] === 'finalizada') {
                    $res = resultadoPartida($semi);
                    if ($res['v1']) { $perdedor1 = $semi['time2']; }
                    elseif ($res['v2']) { $perdedor1 = $semi['time1']; }
                }
                if ($semi['id'] === 102 && $semi['status'] === 'finalizada') {
                    $res = resultadoPartida($semi);
                    if ($res['v1']) { $perdedor2 = $semi['time2']; }
                    elseif ($res['v2']) { $perdedor2 = $semi['time1']; }
                }
            }
            $p['time1'] = $perdedor1;
            $p['time2'] = $perdedor2;
        }
    }
    unset($p);

    salvarJson(CHAVEAMENTO_FILE, $chaveamento);
}

function resolverTime(string $spec, array $lideres, array $vices, array $terceiros, array $melhoresTerc): string {
    if (str_starts_with($spec, '1')) {
        $grupo = substr($spec, 1);
        return $lideres[$grupo] ?? 'A definir';
    }

    if (str_starts_with($spec, '2')) {
        $grupo = substr($spec, 1);
        return $vices[$grupo] ?? 'A definir';
    }

    if (str_starts_with($spec, '3')) {
        $grupo = substr($spec, 1);
        if (in_array($grupo, $melhoresTerc)) {
            return $terceiros[$grupo]['time'] ?? 'A definir';
        }
        return 'A definir';
    }

    return 'A definir';
}

// ============================================================
// RENDER — Funções de renderização HTML
// ============================================================

function renderGrupos(array $partidas, string $grupo_ativo, array $classificacao): string {
    ob_start();
    ?>
    <div class="mb-6"><h2 class="font-titulo font-bold text-xl"><i class="ri-group-fill text-[#c9b896]"></i> Fase de Grupos</h2></div>
    <div class="flex flex-wrap gap-2 mb-6">
        <?php foreach(range('A','L') as $g): ?>
        <a href="?aba=grupos&grupo=<?=$g?>" class="grupo-tab px-4 py-2 rounded-lg text-sm border border-[#c9b896]/15 <?=$g===$grupo_ativo?'active':'bg-[#191817] text-[#f8f7f4]/60'?>">Grupo <?=$g?></a>
        <?php endforeach; ?>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-2 space-y-4">
            <h3 class="font-titulo font-semibold text-sm text-[#c9b896] uppercase"><i class="ri-calendar-event-line"></i> Partidas — Grupo <?=$grupo_ativo?></h3>
            <?php
            $por_grupo = [];
            foreach ($partidas as $p) if (($p['fase']??'') === 'grupos') $por_grupo[$p['grupo']][] = $p;
            foreach ($por_grupo as &$pg) usort($pg, fn($a,$b) => $a['rodada'] - $b['rodada']);
            $pgrupo = $por_grupo[$grupo_ativo] ?? [];
            foreach ($pgrupo as $p):
                $fin = $p['status'] === 'finalizada';
                $gols_casa = eventosPorTime($p['eventos'] ?? [], 'casa');
                $gols_fora = eventosPorTime($p['eventos'] ?? [], 'fora');
                $cartoes_casa = eventosPorTime($p['cartoes'] ?? [], 'casa');
                $cartoes_fora = eventosPorTime($p['cartoes'] ?? [], 'fora');
            ?>
            <div class="card <?=$fin?'finalizada':'agendada'?> p-5">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex-1 text-right flex items-center justify-end gap-3">
                        <span class="font-medium text-base"><?=htmlspecialchars($p['time1'])?></span><?=bandeiraSvg($p['time1'])?>
                    </div>
                    <div class="flex items-center gap-3 px-4">
                        <span class="text-3xl font-bold font-mono <?=$p['gols1']===null?'text-[#555]':'text-[#f8f7f4]'?>"><?=$p['gols1']??'—'?></span>
                        <span class="text-[#f8f7f4]/30 text-lg">×</span>
                        <span class="text-3xl font-bold font-mono <?=$p['gols2']===null?'text-[#555]':'text-[#f8f7f4]'?>"><?=$p['gols2']??'—'?></span>
                    </div>
                    <div class="flex-1 text-left flex items-center gap-3">
                        <?=bandeiraSvg($p['time2'])?><span class="font-medium text-base"><?=htmlspecialchars($p['time2'])?></span>
                    </div>
                    <button onclick="abrirModalEdicao(<?=$p['id']?>)" class="p-2 rounded-lg bg-[#c9b896]/10 text-[#c9b896] hover:text-[#ccb47f]" title="Editar"><i class="ri-edit-line text-xl"></i></button>
                </div>
                <?php if (!empty($p['eventos']) || !empty($p['cartoes'])): ?>
                <div class="mt-3 grid grid-cols-2 gap-4 text-sm">
                    <div class="text-left space-y-1">
                        <?php foreach ($gols_casa as $ev): ?>
                        <div class="flex items-center gap-1.5">
                            <i class="ri-football-fill <?=!empty($ev['gol_contra'])?'text-red-500':'text-[#c9b896]'?>"></i>
                            <span class="<?=!empty($ev['gol_contra'])?'gc-texto':''?>"><?=htmlspecialchars($ev['jogador'])?><?php if(!empty($ev['gol_contra'])): ?>(GC)<?php endif; ?></span>
                            <?=formatarMinuto($ev['minuto'])?>
                        </div>
                        <?php endforeach; ?>
                        <?php foreach ($cartoes_casa as $c): ?>
                        <div class="flex items-center gap-1.5"><i class="ri-square-fill <?=$c['tipo']==='amarelo'?'text-yellow-400':'text-red-600'?> text-sm"></i> <?=htmlspecialchars($c['jogador'])?> <?=formatarMinuto($c['minuto'])?></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-right space-y-1">
                        <?php foreach ($gols_fora as $ev): ?>
                        <div class="flex items-center justify-end gap-1.5">
                            <?=formatarMinuto($ev['minuto'])?> <span class="<?=!empty($ev['gol_contra'])?'gc-texto':''?>"><?=htmlspecialchars($ev['jogador'])?><?php if(!empty($ev['gol_contra'])): ?>(GC)<?php endif; ?></span>
                            <i class="ri-football-fill <?=!empty($ev['gol_contra'])?'text-red-500':'text-[#c9b896]'?>"></i>
                        </div>
                        <?php endforeach; ?>
                        <?php foreach ($cartoes_fora as $c): ?>
                        <div class="flex items-center justify-end gap-1.5"><?=formatarMinuto($c['minuto'])?> <?=htmlspecialchars($c['jogador'])?> <i class="ri-square-fill <?=$c['tipo']==='amarelo'?'text-yellow-400':'text-red-600'?> text-sm"></i></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="flex flex-wrap items-center gap-3 mt-3 pt-3 border-t border-[#ffffff08] text-xs text-[#f8f7f4]/45 font-mono">
                    <span><i class="ri-calendar-line text-[#c9b896]/60"></i> <?=date('d/m/Y', strtotime($p['data']))?></span>
                    <span><i class="ri-time-line text-[#c9b896]/60"></i> <?=$p['horario']?></span>
                    <span><i class="ri-map-pin-line text-[#c9b896]/60"></i> <?=htmlspecialchars($p['estadio'])?></span>
                    <span class="ml-auto badge-status text-[10px] font-semibold <?=$fin?'text-[#c9b896] bg-[#313d26]/40':'text-[#f8f7f4]/40 bg-[#ffffff08]'?> px-2 py-0.5 rounded-full"><?=$fin?'Finalizada':'Agendada'?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="lg:col-span-1">
            <div class="card p-5 lg:p-6 sticky top-24 card-classificacao">
                <h3 class="font-titulo font-semibold text-sm text-[#c9b896] uppercase mb-4"><i class="ri-medal-line"></i> Classificação — Grupo <?=$grupo_ativo?></h3>
                <?php if (empty($classificacao)): ?><p class="text-[#f8f7f4]/40 text-sm text-center py-6">Nenhum resultado ainda.</p>
                <?php else: ?>
                    <div class="overflow-x-auto"><table class="tabela-classificacao w-full text-left">
                        <thead><tr class="border-b border-[#ffffff08]"><th class="pb-2 pr-2 w-8">#</th><th class="pb-2 pr-2 min-w-[150px]">EQUIPA</th><th class="pb-2 px-1 text-center">J</th><th class="pb-2 px-1 text-center">V</th><th class="pb-2 px-1 text-center">E</th><th class="pb-2 px-1 text-center">D</th><th class="pb-2 px-1 text-center">GM</th><th class="pb-2 px-1 text-center">GS</th><th class="pb-2 px-1 text-center">SG</th><th class="pb-2 px-1 text-center">PTS</th></tr></thead>
                        <tbody><?php $pos=1; foreach ($classificacao as $time => $st): ?>
                            <tr class="border-b border-[#ffffff04] hover:bg-[#ffffff03] transition-colors <?=$pos<=2?'text-[#f8f7f4]':'text-[#f8f7f4]/70'?>">
                                <td class="py-2 pr-2 font-bold text-xs"><?=$pos?></td>
                                <td class="py-2 pr-2 nome-time flex items-center gap-2"><?=bandeiraSvg($time)?> <?=htmlspecialchars($time)?></td>
                                <td class="py-2 px-1 text-center"><?=$st['j']?></td><td class="py-2 px-1 text-center"><?=$st['v']?></td><td class="py-2 px-1 text-center"><?=$st['e']?></td><td class="py-2 px-1 text-center"><?=$st['d']?></td>
                                <td class="py-2 px-1 text-center"><?=$st['gm']?></td><td class="py-2 px-1 text-center"><?=$st['gs']?></td><td class="py-2 px-1 text-center"><?=$st['sg']>=0?'+'.$st['sg']:$st['sg']?></td>
                                <td class="py-2 px-1 text-center font-bold text-[#c9b896]"><?=$st['pts']?></td>
                            </tr>
                        <?php $pos++; endforeach; ?></tbody>
                    </table></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function renderMatchCardChave(array $j, int $w, int $h, bool $destaque = false): string {
    $res = resultadoPartida($j);
    $v1 = $res['v1']; $v2 = $res['v2']; $decPen = $res['penaltis'];
    $isADefinir = ($j['time1'] === 'A definir' || $j['time2'] === 'A definir');
    $finalizada = $j['status'] === 'finalizada';
    $subtitulo = '';
    if ($decPen) {
        $subtitulo = 'Pên. ' . ($j['penaltis1'] ?? '?') . '×' . ($j['penaltis2'] ?? '?');
    } elseif (!$isADefinir) {
        $subtitulo = date('d/m', strtotime($j['data'])) . ' · ' . $j['horario'];
    } elseif (!empty($j['info'])) {
        $subtitulo = $j['info'];
    }

    ob_start();
    ?>
    <div class="chave-card <?= $finalizada ? 'chave-fin' : 'chave-pend' ?> <?= $isADefinir ? 'opacity-55' : '' ?> <?= $destaque ? 'chave-destaque' : '' ?>"
         style="width:<?= $w ?>px;height:<?= $h ?>px;position:relative;"
         onclick="abrirModalEdicao(<?= $j['id'] ?>)" title="Editar partida">
        <?php if (!empty($j['manual'])): ?>
        <span class="chave-manual-pin" title="Seleção travada manualmente"><i class="ri-lock-2-fill"></i></span>
        <?php endif; ?>
        <div class="chave-time">
            <span class="chave-nome <?= $v1 ? 'chave-vencedor' : '' ?>">
                <?php if ($j['time1'] !== 'A definir'): ?><span class="chave-bandeira"><?= bandeiraSvg($j['time1']) ?></span><?php endif; ?>
                <span class="truncate"><?= htmlspecialchars($j['time1']) ?></span>
            </span>
            <span class="chave-placar <?= $j['gols1'] === null ? 'chave-placar-vazio' : ($v1 ? 'chave-placar-vencedor' : '') ?>"><?= $j['gols1'] ?? '–' ?><?php if ($decPen && isset($j['penaltis1']) && $j['penaltis1'] !== null): ?><span class="chave-pen">(<?= $j['penaltis1'] ?>)</span><?php endif; ?></span>
        </div>
        <div class="chave-time">
            <span class="chave-nome <?= $v2 ? 'chave-vencedor' : '' ?>">
                <?php if ($j['time2'] !== 'A definir'): ?><span class="chave-bandeira"><?= bandeiraSvg($j['time2']) ?></span><?php endif; ?>
                <span class="truncate"><?= htmlspecialchars($j['time2']) ?></span>
            </span>
            <span class="chave-placar <?= $j['gols2'] === null ? 'chave-placar-vazio' : ($v2 ? 'chave-placar-vencedor' : '') ?>"><?= $j['gols2'] ?? '–' ?><?php if ($decPen && isset($j['penaltis2']) && $j['penaltis2'] !== null): ?><span class="chave-pen">(<?= $j['penaltis2'] ?>)</span><?php endif; ?></span>
        </div>
        <?php if ($subtitulo): ?><div class="chave-sub truncate"><?= htmlspecialchars($subtitulo) ?></div><?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function computeCentrosBracket(int $n0, float $cardH, float $vGap0, float $topPad, int $rodadas): array {
    $centros = [];
    for ($i = 0; $i < $n0; $i++) {
        $centros[0][$i] = $i * ($cardH + $vGap0) + $cardH / 2 + $topPad;
    }
    for ($r = 1; $r < $rodadas; $r++) {
        $prev = $centros[$r - 1];
        $n = intdiv(count($prev), 2);
        for ($j = 0; $j < $n; $j++) {
            $centros[$r][$j] = ($prev[2 * $j] + $prev[2 * $j + 1]) / 2;
        }
    }
    return $centros;
}

// Desenha as 4 linhas (2 palitos + 1 vertical + 1 palito) que ligam duas partidas
// de uma rodada à partida seguinte. Funciona nos dois sentidos (esquerda→direita
// no lado A, direita→esquerda no lado B) bastando informar qual borda de cada
// card encosta no vão entre as colunas.
function chaveConector(array $centrosPrev, array $centrosNext, float $xOrigem, float $xDestino): string {
    $n = intdiv(count($centrosPrev), 2);
    $xMeio = ($xOrigem + $xDestino) / 2;
    $left1 = min($xOrigem, $xMeio); $w1 = abs($xMeio - $xOrigem);
    $left3 = min($xMeio, $xDestino); $w3 = abs($xDestino - $xMeio);
    $html = '';
    for ($j = 0; $j < $n; $j++) {
        $y1 = $centrosPrev[2 * $j]; $y2 = $centrosPrev[2 * $j + 1]; $yFilho = $centrosNext[$j];
        $yTopo = min($y1, $y2); $yAltura = abs($y2 - $y1);
        $html .= '<div class="chave-linha-h" style="top:' . ($y1 - 1) . 'px;left:' . $left1 . 'px;width:' . $w1 . 'px;"></div>';
        $html .= '<div class="chave-linha-h" style="top:' . ($y2 - 1) . 'px;left:' . $left1 . 'px;width:' . $w1 . 'px;"></div>';
        $html .= '<div class="chave-linha-v" style="left:' . ($xMeio - 1) . 'px;top:' . $yTopo . 'px;height:' . $yAltura . 'px;"></div>';
        $html .= '<div class="chave-linha-h" style="top:' . ($yFilho - 1) . 'px;left:' . $left3 . 'px;width:' . $w3 . 'px;"></div>';
    }
    return $html;
}

function renderChaveamento(array $partidas): string {
    // Estrutura igual à página oficial da FIFA: duas metades do chaveamento
    // (8 jogos da Rodada de 32 cada) avançam em espelho e só se encontram na Final,
    // com a disputa de 3º lugar isolada logo abaixo.
    $fasesLado = ['16avos', 'oitavas', 'quartas', 'semi'];
    $titulos = [
        '16avos'  => 'Rodada de 32',
        'oitavas' => 'Oitavas',
        'quartas' => 'Quartas',
        'semi'    => 'Semifinal',
    ];

    $jogosPorFase = [];
    foreach (array_merge($fasesLado, ['final']) as $key) {
        $jogos = array_values(array_filter($partidas, fn($p) => ($p['fase'] ?? '') === $key));
        usort($jogos, fn($a, $b) => $a['id'] - $b['id']);
        $jogosPorFase[$key] = $jogos;
    }
    $final = $jogosPorFase['final'][0] ?? null;
    $terceiro = null;
    foreach ($partidas as $p) if (($p['fase'] ?? '') === 'terceiro') { $terceiro = $p; break; }

    // Cada rodada é dividida ao meio: os primeiros jogos (por id) formam o Lado A,
    // os demais o Lado B — a mesma divisão que já alimenta oitavas/quartas/semi/final.
    $ladoA = []; $ladoB = [];
    foreach ($fasesLado as $fase) {
        $todos = $jogosPorFase[$fase];
        $metade = intdiv(count($todos), 2);
        $ladoA[$fase] = array_slice($todos, 0, $metade);
        $ladoB[$fase] = array_slice($todos, $metade);
    }

    // Ao travar seleções manualmente, nada impede o mesmo time de aparecer em duas
    // partidas da Rodada de 32 — avisamos aqui para facilitar a conferência.
    $contagemTimes16 = [];
    foreach ($jogosPorFase['16avos'] as $j16) {
        foreach ([$j16['time1'], $j16['time2']] as $t) {
            if ($t !== 'A definir') $contagemTimes16[$t] = ($contagemTimes16[$t] ?? 0) + 1;
        }
    }
    $duplicados16 = array_keys(array_filter($contagemTimes16, fn($c) => $c > 1));

    // --- Geometria: as duas metades têm o mesmo número de jogos por rodada, então
    //     compartilham as mesmas posições verticais — só a coluna (X) é espelhada.
    $cardW = 156; $cardH = 64; $vGap0 = 12; $colGap = 32; $topPad = 34;
    $finalW = 190; $finalH = 74;
    $ultimaRodada = count($fasesLado) - 1;

    $n0 = count($ladoA['16avos']);
    $centros = computeCentrosBracket($n0, $cardH, $vGap0, $topPad, count($fasesLado));

    $leftX = fn($r) => $r * ($cardW + $colGap);
    $larguraLado = count($fasesLado) * $cardW + ($ultimaRodada) * $colGap;
    $centerX = $larguraLado + $colGap;
    $rightX = fn($r) => $centerX + $finalW + $colGap + ($ultimaRodada - $r) * ($cardW + $colGap);

    $finalY = $centros[$ultimaRodada][0];
    $alturaLado = $n0 * ($cardH + $vGap0) - $vGap0 + $topPad;
    $terceiroTop = $alturaLado + 44;
    $alturaTotal = $terceiroTop + 26 + $finalH + 24;
    $larguraTotal = $rightX(0) + $cardW + 30;

    ob_start();
    ?>
    <div class="mb-6">
        <h2 class="font-titulo font-bold text-xl"><i class="ri-mind-map text-[#c9b896]"></i> Chaveamento</h2>
        <p class="text-sm text-[#f8f7f4]/50">Clique em qualquer jogo para editar o resultado — os vencedores avançam automaticamente.</p>
    </div>

    <?php if (!empty($duplicados16)): ?>
    <div class="mb-6 px-4 py-3 rounded-xl bg-red-500/10 border border-red-500/25 text-red-300 text-sm flex items-start gap-2">
        <i class="ri-error-warning-line text-lg flex-shrink-0"></i>
        <span>Atenção: <strong><?= htmlspecialchars(implode(', ', $duplicados16)) ?></strong> aparece em mais de uma partida da Rodada de 32. Confira as seleções travadas manualmente.</span>
    </div>
    <?php endif; ?>

    <div class="overflow-x-auto pb-6">
        <div class="chave-bracket" style="position:relative;height:<?= $alturaTotal ?>px;min-width:<?= $larguraTotal ?>px;">

            <?php // Cabeçalhos das rodadas — espelhados nos dois lados, convergindo para a Final ?>
            <?php foreach ($fasesLado as $r => $fase): ?>
            <div class="chave-titulo-rodada" style="left:<?= $leftX($r) ?>px;width:<?= $cardW ?>px;top:<?= $topPad - 32 ?>px;"><?= $titulos[$fase] ?></div>
            <div class="chave-titulo-rodada" style="left:<?= $rightX($r) ?>px;width:<?= $cardW ?>px;top:<?= $topPad - 32 ?>px;"><?= $titulos[$fase] ?></div>
            <?php endforeach; ?>
            <div class="chave-titulo-rodada chave-titulo-final" style="left:<?= $centerX ?>px;width:<?= $finalW ?>px;top:<?= $topPad - 32 ?>px;"><i class="ri-trophy-fill"></i> Final</div>

            <?php // Linhas de conexão dentro de cada lado (A cresce → direita, B cresce → esquerda) ?>
            <?php for ($r = 0; $r < $ultimaRodada; $r++): ?>
                <?= chaveConector($centros[$r], $centros[$r + 1], $leftX($r) + $cardW, $leftX($r + 1)) ?>
                <?= chaveConector($centros[$r], $centros[$r + 1], $rightX($r), $rightX($r + 1) + $cardW) ?>
            <?php endfor; ?>

            <?php // Linhas ligando as duas semifinais à Final, no centro do bracket ?>
            <div class="chave-linha-h" style="top:<?= $finalY - 1 ?>px;left:<?= $leftX($ultimaRodada) + $cardW ?>px;width:<?= $centerX - ($leftX($ultimaRodada) + $cardW) ?>px;"></div>
            <div class="chave-linha-h" style="top:<?= $finalY - 1 ?>px;left:<?= $centerX + $finalW ?>px;width:<?= $rightX($ultimaRodada) - ($centerX + $finalW) ?>px;"></div>

            <?php // Cards das partidas — Lado A à esquerda, Lado B à direita (espelhado) ?>
            <?php foreach ($fasesLado as $r => $fase): ?>
                <?php foreach ($ladoA[$fase] as $idx => $partida): ?>
                <div style="position:absolute;left:<?= $leftX($r) ?>px;top:<?= $centros[$r][$idx] - $cardH / 2 ?>px;"><?= renderMatchCardChave($partida, $cardW, $cardH) ?></div>
                <?php endforeach; ?>
                <?php foreach ($ladoB[$fase] as $idx => $partida): ?>
                <div style="position:absolute;left:<?= $rightX($r) ?>px;top:<?= $centros[$r][$idx] - $cardH / 2 ?>px;"><?= renderMatchCardChave($partida, $cardW, $cardH) ?></div>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <?php // Final, em destaque no centro do bracket ?>
            <?php if ($final): ?>
            <div style="position:absolute;left:<?= $centerX ?>px;top:<?= $finalY - $finalH / 2 ?>px;"><?= renderMatchCardChave($final, $finalW, $finalH, true) ?></div>
            <?php endif; ?>

            <?php // Disputa de 3º lugar — não é alimentada por vencedores, fica isolada abaixo da Final ?>
            <?php if ($terceiro): ?>
            <div class="chave-titulo-rodada" style="left:<?= $centerX ?>px;width:<?= $finalW ?>px;top:<?= $terceiroTop ?>px;"><i class="ri-medal-2-line"></i> 3º Lugar</div>
            <div style="position:absolute;left:<?= $centerX ?>px;top:<?= $terceiroTop + 26 ?>px;"><?= renderMatchCardChave($terceiro, $finalW, $finalH) ?></div>
            <?php endif; ?>

        </div>
    </div>
    <?php
    return ob_get_clean();
}

function renderEstatisticas(array $partidas): string {
    ob_start();
    $total_gols=0;$finalizadas=0;$gols_por_time=[];
    foreach($partidas as $p){
        if($p['gols1']!==null && $p['gols2']!==null){
            $total_gols += $p['gols1'] + $p['gols2'];
            $finalizadas++;
            foreach([$p['time1'], $p['time2']] as $t) {
                if(!isset($gols_por_time[$t])) $gols_por_time[$t] = ['gp'=>0, 'gc'=>0];
            }
            $gols_por_time[$p['time1']]['gp'] += $p['gols1'];
            $gols_por_time[$p['time1']]['gc'] += $p['gols2'];
            $gols_por_time[$p['time2']]['gp'] += $p['gols2'];
            $gols_por_time[$p['time2']]['gc'] += $p['gols1'];
        }
    }
    $media = $finalizadas ? round($total_gols / $finalizadas, 2) : 0;
    uasort($gols_por_time, fn($a, $b) => $b['gp'] <=> $a['gp']);
    $top10_times = array_slice($gols_por_time, 0, 10, true);
    $artilheiros = calcularArtilheiros();
    $top10_artilheiros = array_slice($artilheiros, 0, 10, true);
    ?>
    <div class="mb-6"><h2 class="font-titulo font-bold text-xl"><i class="ri-bar-chart-2-fill text-[#c9b896]"></i> Estatísticas do Torneio</h2></div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="card p-6 text-center"><i class="ri-football-fill text-3xl text-[#c9b896] mb-2"></i><p class="text-3xl font-bold font-mono"><?=$total_gols?></p><p class="text-xs text-[#f8f7f4]/50 mt-1">Total de Gols</p></div>
        <div class="card p-6 text-center"><i class="ri-checkbox-circle-fill text-3xl text-[#c9b896] mb-2"></i><p class="text-3xl font-bold font-mono"><?=$finalizadas?></p><p class="text-xs text-[#f8f7f4]/50 mt-1">Partidas Finalizadas</p></div>
        <div class="card p-6 text-center"><i class="ri-speed-line text-3xl text-[#c9b896] mb-2"></i><p class="text-3xl font-bold font-mono"><?=$media?></p><p class="text-xs text-[#f8f7f4]/50 mt-1">Média Gols/Jogo</p></div>
        <div class="card p-6 text-center"><i class="ri-global-line text-3xl text-[#c9b896] mb-2"></i><p class="text-3xl font-bold font-mono">48</p><p class="text-xs text-[#f8f7f4]/50 mt-1">Seleções</p></div>
    </div>
    <div class="card p-6 mb-8"><h3 class="font-titulo font-semibold text-sm text-[#c9b896] uppercase mb-4"><i class="ri-shield-fill"></i> Top 10 Seleções</h3>
        <?php if(empty($top10_times)):?><p class="text-[#f8f7f4]/40 text-sm">Nenhum dado.</p><?php else:?><div class="space-y-4"><?php $rank=1;foreach($top10_times as $time=>$st):?>
            <div class="flex items-center gap-4"><span class="w-8 h-8 rounded-full bg-[#c9b896]/15 text-[#c9b896] flex items-center justify-center text-xs font-bold font-mono"><?=$rank?></span><span class="flex-1 text-base flex items-center gap-2"><?=bandeiraSvg($time)?> <?=htmlspecialchars($time)?></span><span class="font-mono font-bold text-[#c9b896] text-lg"><?=$st['gp']?> <span class="text-[#f8f7f4]/30 text-xs">gols</span></span></div>
        <?php $rank++; endforeach;?></div><?php endif;?>
    </div>
    <?php
    $todasPartidasStats = array_merge(carregarGrupos(), carregarChaveamento());
    $finalizadasStats = array_values(array_filter($todasPartidasStats, fn($p) => ($p['status'] ?? '') === 'finalizada'));
    $comEventosCompletosStats = array_values(array_filter($finalizadasStats, fn($p) => !empty($p['dados_completos_fifa']) || (($p['fifa_status_campos']['gols'] ?? '') === 'confirmados')));
    $coberturaArtilharia = count($finalizadasStats) ? round(count($comEventosCompletosStats) * 100 / count($finalizadasStats), 1) : 0;
?>
    <div class="card p-6"><h3 class="font-titulo font-semibold text-sm text-[#c9b896] uppercase mb-2"><i class="ri-user-star-fill"></i> Artilheiros</h3>
        <?php if ($coberturaArtilharia < 100): ?>
        <div class="mb-4 rounded-xl border border-amber-400/20 bg-amber-400/5 px-4 py-3 text-xs text-amber-200/80">
            Cobertura oficial dos autores dos gols: <?= number_format($coberturaArtilharia, 1, ',', '.') ?>%. O ranking abaixo considera somente gols cadastrados e não deve ser tratado como classificação oficial enquanto a cobertura não atingir 100%.
        </div>
        <?php endif; ?>
        <?php if(empty($top10_artilheiros)):?><p class="text-[#f8f7f4]/40 text-sm">Nenhum gol registrado.</p><?php else:?><div class="space-y-4"><?php $rank=1;foreach($top10_artilheiros as $nome=>$dados):?>
            <div class="flex items-center gap-4"><span class="w-8 h-8 rounded-full bg-[#c9b896]/15 text-[#c9b896] flex items-center justify-center text-xs font-bold font-mono"><?=$rank?></span><span class="flex-1 text-base flex items-center gap-2"><?php if(!empty($dados['selecao'])):?><?=bandeiraSvg($dados['selecao'])?><?php endif;?><span class="font-medium"><?=htmlspecialchars($nome)?></span><?php if(!empty($dados['selecao'])):?><span class="text-[#f8f7f4]/50 text-sm">(<?=htmlspecialchars($dados['selecao'])?>)</span><?php endif;?></span><span class="font-mono font-bold text-[#c9b896] text-lg"><?=$dados['gols']?> <span class="text-[#f8f7f4]/30 text-xs">gols</span></span></div>
        <?php $rank++; endforeach;?></div><?php endif;?>
    </div>
    <?php $adv=calcularEstatisticasAvancadas($partidas);$topSel=array_slice($adv['selecoes'],0,12,true);$topEst=array_slice($adv['estadios'],0,8,true); ?>
    <div class="grid lg:grid-cols-2 gap-6 mt-8">
      <div class="card p-6"><h3 class="font-titulo font-semibold text-sm text-[#c9b896] uppercase mb-4">Desempenho das seleções</h3><div class="overflow-x-auto"><table class="w-full text-xs"><thead><tr class="text-[#c9b896]"><th class="text-left py-2">Seleção</th><th>J</th><th>PTS</th><th>APR.</th><th>SG</th><th>Invicta</th><th>Clean</th></tr></thead><tbody><?php foreach($topSel as $tm=>$st):?><tr class="border-t border-white/5"><td class="py-2 flex items-center gap-2"><?=bandeiraSvg($tm)?><?=htmlspecialchars($tm)?></td><td class="text-center"><?=$st['j']?></td><td class="text-center"><?=$st['pts']?></td><td class="text-center"><?=$st['aproveitamento']?>%</td><td class="text-center"><?=$st['sg']?></td><td class="text-center"><?=$st['max_inv']?></td><td class="text-center"><?=$st['clean']?></td></tr><?php endforeach;?></tbody></table></div></div>
      <div class="card p-6"><h3 class="font-titulo font-semibold text-sm text-[#c9b896] uppercase mb-4">Gols por período</h3><div class="space-y-3"><?php $mx=max($adv['intervalos']?:[1]);foreach($adv['intervalos'] as $faixa=>$q):?><div><div class="flex justify-between text-xs mb-1"><span><?=$faixa?> min</span><b><?=$q?></b></div><div class="h-2 bg-black rounded-full overflow-hidden"><div class="h-full bg-[#c9b896]" style="width:<?=round(($q/max(1,$mx))*100)?>%"></div></div></div><?php endforeach;?></div></div>
      <div class="card p-6"><h3 class="font-titulo font-semibold text-sm text-[#c9b896] uppercase mb-4">Estádios com mais gols</h3><div class="space-y-3"><?php foreach($topEst as $est=>$st):?><div class="flex justify-between gap-3 text-sm"><span class="truncate"><?=htmlspecialchars($est)?></span><span class="font-mono text-[#c9b896]"><?=$st['gols']?> gols · <?=$st['jogos']?> jogos</span></div><?php endforeach;?></div></div>
      <div class="card p-6"><h3 class="font-titulo font-semibold text-sm text-[#c9b896] uppercase mb-4">Gols por fase</h3><div class="space-y-3"><?php foreach($adv['fases'] as $fase=>$q):?><div class="flex justify-between text-sm"><span><?=htmlspecialchars($fase)?></span><b class="font-mono text-[#c9b896]"><?=$q?></b></div><?php endforeach;?></div></div>
    </div>
    <?php
    return ob_get_clean();
}

// ============================================================
// ROTEADOR PRINCIPAL
// ============================================================

$redirect = processarSalvamento();
if ($redirect) { header("Location: $redirect"); exit; }

$partidas = carregarTodasPartidas();
$totalFinalizadas = count(array_filter($partidas, fn($p) => $p['status'] === 'finalizada'));

$aba = $_GET['aba'] ?? 'grupos';
$grupo_ativo = $_GET['grupo'] ?? 'A';
if (!in_array($grupo_ativo, range('A', 'L'))) $grupo_ativo = 'A';

$todos_times = getTodosTimes();
$estadios_unicos = getEstadiosUnicos($partidas);
$classificacao = calcularClassificacao($partidas, $grupo_ativo);
$msg = ($_GET['msg'] ?? '') === 'ok' ? 'Partida atualizada com sucesso!' : '';
$listaJogadores = coletarNomesJogadores();

$errosIntegridade = validarIntegridade();
if (!empty($errosIntegridade)) {
    error_log('Erros de integridade: ' . implode('; ', $errosIntegridade));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Copa do Mundo 2026™</title>
    <!-- Tailwind CSS via CDN -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <!-- Flowbite -->
    <link href="https://cdn.jsdelivr.net/npm/flowbite@4.0.1/dist/flowbite.min.css" rel="stylesheet">
    <!-- Remixicon -->
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.9.1/fonts/remixicon.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Geist+Mono:wght@400;500;600;700&family=Inter:wght@300;400;500;600&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">


    <style>
        :root{--bg:#000;--bg2:#191817;--txt:#f8f7f4;--accent:#c9b896}
        body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--txt)}
        .font-titulo{font-family:'Montserrat',sans-serif}.font-mono{font-family:'Geist Mono',monospace}
        .btn-primary{background:linear-gradient(135deg,#ccb47f,#c9b896);color:#000;font-weight:600}
        .card{background:var(--bg2);border:1px solid rgba(201,184,150,0.15);border-radius:1rem}
        .card.finalizada{border-left:3px solid var(--accent)}.card.agendada{border-left:3px solid #555}
        .grupo-tab.active{background:linear-gradient(135deg,#ccb47f,#c9b896);color:#000}
        .toast{position:fixed;top:1.5rem;right:1.5rem;z-index:9999}
        .tabela-classificacao th{font-family:'Montserrat',sans-serif;font-weight:600;font-size:.7rem;letter-spacing:.06em;color:var(--accent)}
        .tabela-classificacao td{font-family:'Geist Mono',monospace;font-size:.75rem}
        .tabela-classificacao .nome-time{font-family:'Inter',sans-serif;font-weight:500;font-size:.85rem;white-space:nowrap}
        .card-classificacao{min-width:340px}
        .gc-texto{color:#ef4444;font-weight:700}.minuto-acrescimo{color:#f8f7f4}.minuto-acrescimo .acrescimo{color:#c9b896;font-weight:bold}
        input[type="number"]::-webkit-inner-spin-button,input[type="number"]::-webkit-outer-spin-button{opacity:1;height:30px;cursor:pointer}
        .spinner-btn{width:28px;height:28px;display:flex;align-items:center;justify-content:center;background:rgba(201,184,150,0.15);color:#c9b896;border-radius:6px;cursor:pointer;font-size:14px;user-select:none;transition:all 0.2s}
        .spinner-btn:hover{background:rgba(201,184,150,0.3)}.spinner-btn:active{transform:scale(0.95)}
        .gc-hint{font-size:10px;color:#ef4444;margin-left:4px}
        .border-l-3 { border-left-width: 3px; }
        .border-l-2 { border-left-width: 2px; }

        /* ---- Chaveamento (mata-mata) ---- */
        .chave-titulo-rodada{position:absolute;top:-32px;text-align:center;font-family:'Montserrat',sans-serif;font-size:.68rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#c9b896;opacity:.85}
        .chave-linha-h{position:absolute;height:2px;background:linear-gradient(90deg,rgba(201,184,150,.55),rgba(201,184,150,.25));}
        .chave-linha-v{position:absolute;width:2px;background:rgba(201,184,150,.4);}
        .chave-card{background:#191817;border:1px solid rgba(201,184,150,.15);border-radius:10px;padding:8px 10px;cursor:pointer;display:flex;flex-direction:column;justify-content:center;gap:4px;transition:all .15s ease;box-shadow:0 1px 3px rgba(0,0,0,.3)}
        @keyframes bracketIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}.bracket-enter{animation:bracketIn .35s ease both}.chave-card:hover{border-color:rgba(201,184,150,.55);background:#1f1e1b;transform:translateY(-1px);box-shadow:0 4px 10px rgba(0,0,0,.4)}
        .chave-card.chave-fin{border-left:3px solid #c9b896}
        .chave-card.chave-pend{border-left:3px solid #444}
        .chave-card.chave-destaque{background:linear-gradient(135deg,rgba(204,180,127,.12),rgba(201,184,150,.05));border-color:rgba(201,184,150,.5)}
        .chave-time{display:flex;align-items:center;justify-content:space-between;gap:6px;min-width:0}
        .chave-nome{display:flex;align-items:center;gap:5px;font-size:.72rem;color:rgba(248,247,244,.55);min-width:0;overflow:hidden}
        .chave-nome .truncate{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .chave-nome.chave-vencedor{color:#f8f7f4;font-weight:700}
        .chave-bandeira{flex-shrink:0;line-height:0}
        .chave-bandeira img{width:16px!important;height:11px!important;margin:0!important}
        .chave-placar{flex-shrink:0;font-family:'Geist Mono',monospace;font-weight:700;font-size:.82rem;color:#666;min-width:16px;text-align:center}
        .chave-placar-vazio{color:#3a3a3a}
        .chave-placar-vencedor{color:#c9b896}
        .chave-pen{font-size:.6rem;font-weight:600;color:rgba(248,247,244,.4);margin-left:2px}
        .chave-sub{font-size:.55rem;color:rgba(248,247,244,.25);font-family:'Geist Mono',monospace;text-align:center;margin-top:2px;padding-top:3px;border-top:1px solid rgba(255,255,255,.05)}
        .chave-manual-pin{position:absolute;top:-6px;right:-6px;width:18px;height:18px;border-radius:50%;background:#c9b896;color:#000;display:flex;align-items:center;justify-content:center;font-size:9px;box-shadow:0 1px 4px rgba(0,0,0,.5)}
        .chave-titulo-final{color:#f2d9a6;font-size:.74rem}

        .input-ui{background:#111;border:1px solid rgba(201,184,150,.18);border-radius:.85rem;padding:.8rem 1rem;color:#f8f7f4;outline:none}.input-ui:focus{border-color:rgba(201,184,150,.65);box-shadow:0 0 0 3px rgba(201,184,150,.08)}
        .metric-card{background:linear-gradient(145deg,#1d1c1a,#151412);border:1px solid rgba(201,184,150,.14);border-radius:1rem;padding:1rem;min-height:116px;display:flex;flex-direction:column;justify-content:center}.metric-card i{color:#c9b896;font-size:1.25rem}.metric-card strong{font:700 1.65rem 'Geist Mono';margin-top:.4rem}.metric-card span{font-size:.68rem;color:rgba(248,247,244,.45);margin-top:.25rem}
        .nav-link{padding:.65rem .8rem;border-radius:.65rem;font-size:.72rem;color:rgba(248,247,244,.6);display:flex;align-items:center;gap:.35rem}.nav-link.active{background:linear-gradient(135deg,#ccb47f,#c9b896);color:#000}.nav-link:hover{color:#fff}
        .timeline{position:relative;padding-left:2.5rem}.timeline-line{position:absolute;left:.65rem;top:8px;bottom:8px;width:2px;background:rgba(201,184,150,.2)}.timeline-day{position:relative;margin-bottom:1rem}.timeline-dot{position:absolute;left:-2.18rem;top:1.25rem;width:14px;height:14px;border-radius:50%;background:#333;border:3px solid #000;box-shadow:0 0 0 2px rgba(201,184,150,.35)}.timeline-dot.done{background:#c9b896}.timeline-match{width:100%;text-align:left;background:#111;border:1px solid rgba(255,255,255,.06);border-radius:.65rem;padding:.75rem;display:flex;flex-direction:column;gap:.35rem;transition:.2s}.timeline-match:hover{border-color:rgba(201,184,150,.5);transform:translateY(-1px)}
        .stat-mini{background:#111;border-radius:.75rem;padding:.75rem;text-align:center}.stat-mini strong{display:block;font:700 1.2rem 'Geist Mono';color:#c9b896}.stat-mini span{font-size:.62rem;color:rgba(248,247,244,.4)}.match-history{width:100%;padding:1rem;display:flex;align-items:center;gap:1rem;text-align:left}.result-pill{font-size:.65rem;border-radius:999px;padding:.3rem .55rem;background:#333}.result-vitoria{color:#c9b896;background:rgba(201,184,150,.12)}.result-derrota{color:#f87171;background:rgba(248,113,113,.1)}
        .search-hidden{display:none!important}.save-indicator{position:fixed;bottom:1.2rem;right:1.2rem;z-index:60;background:#191817;border:1px solid rgba(201,184,150,.35);border-radius:999px;padding:.6rem .9rem;font-size:.72rem;display:none}
        @media(max-width:767px){header{align-items:flex-start!important;gap:.75rem;flex-direction:column}.nav-link span{display:none}.nav-link{font-size:1rem}.card-classificacao{min-width:0}.match-history{display:grid;grid-template-columns:1fr auto}.match-history>span:nth-child(2){grid-column:1/3}.chave-bracket{transform-origin:top left}.timeline{padding-left:1.8rem}.timeline-dot{left:-1.48rem}.timeline-line{left:.25rem}.metric-card{min-height:96px;padding:.8rem}.metric-card strong{font-size:1.35rem}}
    </style>
</head>
<body class="antialiased">
    <?php if($msg):?><div class="toast bg-[#191817] border border-[#c9b896]/40 rounded-xl p-4 flex items-center gap-3"><i class="ri-checkbox-circle-fill text-[#c9b896] text-xl"></i><span class="text-sm"><?=$msg?></span><button onclick="this.parentElement.remove()" class="ml-auto text-[#f8f7f4]/50"><i class="ri-close-line"></i></button></div><script>setTimeout(()=>document.querySelector('.toast')?.remove(),4000)</script><?php endif?>

    <header class="sticky top-0 z-40 bg-[#000]/90 backdrop-blur-xl border-b border-[#c9b896]/10 p-4 flex items-center justify-between">
        <h1 class="font-titulo font-bold text-lg"><i class="ri-football-fill text-[#c9b896]"></i> COPA 2026</h1>
        <nav class="flex gap-2 bg-[#191817] rounded-xl p-1">
            <a href="?aba=grupos&grupo=<?=$grupo_ativo?>" class="nav-link <?=$aba==='grupos'?'active':''?>"><i class="ri-group-line"></i><span>Grupos</span></a>
            <a href="?aba=chaveamento" class="nav-link <?=$aba==='chaveamento'?'active':''?>"><i class="ri-mind-map"></i><span>Chaveamento</span></a>
            <a href="?aba=estatisticas" class="nav-link <?=$aba==='estatisticas'?'active':''?>"><i class="ri-bar-chart-line"></i><span>Stats</span></a><a href="?aba=selecoes" class="nav-link <?=$aba==='selecoes'?'active':''?>"><i class="ri-shield-star-line"></i><span>Seleções</span></a><a href="?aba=timeline" class="nav-link <?=$aba==='timeline'?'active':''?>"><i class="ri-timeline-view"></i><span>Timeline</span></a>
        </nav>
    </header>

    <main class="max-w-[1500px] mx-auto p-4">
        <?=renderDashboard($partidas)?>
        <div class="mb-6 relative"><i class="ri-search-line absolute left-4 top-1/2 -translate-y-1/2 text-[#c9b896]"></i><input id="global-search" class="input-ui w-full pl-11" placeholder="Buscar seleção, jogador, estádio, cidade, fase ou grupo..." oninput="filtrarConteudo(this.value)"></div>
        <?php
        if ($aba === 'grupos') {
            echo renderGrupos($partidas, $grupo_ativo, $classificacao);
        } elseif ($aba === 'chaveamento') {
            echo renderChaveamento($partidas);
        } elseif ($aba === 'estatisticas') {
            echo renderEstatisticas($partidas);
        } elseif ($aba === 'selecoes') {
            echo renderSelecoes($partidas, $_GET['selecao'] ?? 'Brasil');
        } elseif ($aba === 'timeline') {
            echo renderTimeline($partidas);
        } else {
            echo renderGrupos($partidas, $grupo_ativo, $classificacao);
        }
        ?>
    </main>

    <div id="modal-backdrop" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 hidden" onclick="fecharModal(event)"><div id="modal" class="bg-[#191817] border border-[#c9b896]/20 rounded-2xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()"><div class="flex items-center justify-between p-5 border-b border-[#ffffff08]"><h3 class="font-titulo font-bold text-lg"><i class="ri-edit-fill text-[#c9b896]"></i> Editar Partida</h3><button onclick="fecharModal()" class="p-2 text-[#f8f7f4]/50"><i class="ri-close-line text-xl"></i></button></div>
    <form method="POST" id="form-edicao" class="p-5 space-y-5"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars(csrfToken())?>"><input type="hidden" name="salvar_partida" value="1"><input type="hidden" name="partida_id" id="input-partida-id"><input type="hidden" name="aba" value="<?=$aba?>"><input type="hidden" name="grupo" value="<?=$grupo_ativo?>">
        <div class="grid grid-cols-2 gap-4"><div><label class="text-xs text-[#c9b896] uppercase">Time 1</label><select name="time1" id="input-time1" class="w-full bg-black border border-[#ffffff10] rounded-xl px-4 py-3 text-sm text-[#f8f7f4]"><?php foreach($todos_times as $t):?><option><?=htmlspecialchars($t)?></option><?php endforeach;?></select></div><div><label class="text-xs text-[#c9b896] uppercase">Time 2</label><select name="time2" id="input-time2" class="w-full bg-black border border-[#ffffff10] rounded-xl px-4 py-3 text-sm text-[#f8f7f4]"><?php foreach($todos_times as $t):?><option><?=htmlspecialchars($t)?></option><?php endforeach;?></select></div></div>
        <div id="bloco-manual" style="display:none" class="bg-[#c9b896]/5 border border-[#c9b896]/20 rounded-xl px-4 py-3"><label class="flex items-start gap-2 text-xs text-[#c9b896] cursor-pointer"><input type="checkbox" name="manual_times" id="input-manual" value="1" class="mt-0.5"><span>Travar esta seleção manualmente<br><span class="text-[#f8f7f4]/40 normal-case">Impede que o time seja recalculado automaticamente pela fase de grupos ao salvar outras partidas. Desmarque para voltar ao cálculo automático.</span></span></label></div>
        <div><label class="text-xs text-[#c9b896] uppercase"><i class="ri-football-line"></i> Placar</label><div class="flex items-center justify-center gap-4"><div class="flex items-center gap-1"><div class="spinner-btn" onclick="alterarGols('gols1',-1)"><i class="ri-subtract-line"></i></div><input type="number" name="gols1" id="input-gols1" min="0" max="20" class="w-16 bg-black border border-[#ffffff10] rounded-xl px-3 py-3 text-center text-lg font-bold font-mono text-[#f8f7f4]" value="0" oninput="atualizarBlocoPenaltis()"><div class="spinner-btn" onclick="alterarGols('gols1',1)"><i class="ri-add-line"></i></div></div><span class="text-[#f8f7f4]/30 text-xl font-mono">×</span><div class="flex items-center gap-1"><div class="spinner-btn" onclick="alterarGols('gols2',-1)"><i class="ri-subtract-line"></i></div><input type="number" name="gols2" id="input-gols2" min="0" max="20" class="w-16 bg-black border border-[#ffffff10] rounded-xl px-3 py-3 text-center text-lg font-bold font-mono text-[#f8f7f4]" value="0" oninput="atualizarBlocoPenaltis()"><div class="spinner-btn" onclick="alterarGols('gols2',1)"><i class="ri-add-line"></i></div></div></div></div>
        <div id="bloco-penaltis" style="display:none" class="rounded-xl overflow-hidden border border-[#c9b896]/30">
            <div class="bg-[#c9b896]/10 px-4 py-2 flex items-center justify-between">
                <span class="text-xs text-[#c9b896] font-titulo font-bold uppercase tracking-wider"><i class="ri-focus-3-line"></i> Disputa de Pênaltis</span>
                <button type="button" onclick="limparPenaltis()" class="text-[0.6rem] text-[#f8f7f4]/40 hover:text-red-400 transition-colors"><i class="ri-close-circle-line"></i> Limpar</button>
            </div>
            <div class="px-4 py-4 bg-[#191817]">
                <div class="flex items-center justify-center gap-4">
                    <div class="text-center">
                        <div class="text-[0.6rem] text-[#f8f7f4]/40 mb-1.5 font-mono uppercase" id="label-pen1">Time 1</div>
                        <div class="flex items-center gap-1">
                            <div class="spinner-btn" onclick="alterarGols('penaltis1',-1)"><i class="ri-subtract-line"></i></div>
                            <input type="number" name="penaltis1" id="input-penaltis1" min="0" max="20" placeholder="–"
                                   class="w-14 bg-black border border-[#ffffff15] rounded-xl px-2 py-2.5 text-center text-xl font-bold font-mono text-[#c9b896]">
                            <div class="spinner-btn" onclick="alterarGols('penaltis1',1)"><i class="ri-add-line"></i></div>
                        </div>
                    </div>
                    <div class="text-[#f8f7f4]/20 text-2xl font-mono pb-5">×</div>
                    <div class="text-center">
                        <div class="text-[0.6rem] text-[#f8f7f4]/40 mb-1.5 font-mono uppercase" id="label-pen2">Time 2</div>
                        <div class="flex items-center gap-1">
                            <div class="spinner-btn" onclick="alterarGols('penaltis2',-1)"><i class="ri-subtract-line"></i></div>
                            <input type="number" name="penaltis2" id="input-penaltis2" min="0" max="20" placeholder="–"
                                   class="w-14 bg-black border border-[#ffffff15] rounded-xl px-2 py-2.5 text-center text-xl font-bold font-mono text-[#c9b896]">
                            <div class="spinner-btn" onclick="alterarGols('penaltis2',1)"><i class="ri-add-line"></i></div>
                        </div>
                    </div>
                </div>
                <p class="text-[0.6rem] text-[#f8f7f4]/30 text-center mt-3">Placar da cobrança de pênaltis — após empate no tempo regulamentar</p>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-4"><div><label class="text-xs text-[#c9b896] uppercase"><i class="ri-calendar-line"></i> Data</label><input type="date" name="data" id="input-data" class="w-full bg-black border border-[#ffffff10] rounded-xl px-4 py-3 text-sm text-[#f8f7f4]"></div><div><label class="text-xs text-[#c9b896] uppercase"><i class="ri-time-line"></i> Horário</label><input type="time" name="horario" id="input-horario" class="w-full bg-black border border-[#ffffff10] rounded-xl px-4 py-3 text-sm text-[#f8f7f4]"></div></div>
        <div class="grid grid-cols-2 gap-4"><div><label class="text-xs text-[#c9b896] uppercase"><i class="ri-map-pin-line"></i> Estádio</label><select name="estadio" id="input-estadio" class="w-full bg-black border border-[#ffffff10] rounded-xl px-4 py-3 text-sm"><?php foreach($estadios_unicos as $est=>$cid):?><option value="<?=htmlspecialchars($est)?>"><?=htmlspecialchars($est)?> (<?=htmlspecialchars($cid)?>)</option><?php endforeach;?></select></div><div><label class="text-xs text-[#c9b896] uppercase"><i class="ri-building-line"></i> Cidade</label><input type="text" name="cidade" id="input-cidade" class="w-full bg-black border border-[#ffffff10] rounded-xl px-4 py-3 text-sm text-[#f8f7f4]" readonly></div></div>
        <div><label class="text-xs text-[#c9b896] uppercase"><i class="ri-flag-line"></i> Status</label><select name="status" id="input-status" class="w-full bg-black border border-[#ffffff10] rounded-xl px-4 py-3 text-sm text-[#f8f7f4]"><option value="agendada">Agendada</option><option value="finalizada">Finalizada</option></select></div>
        <div><label class="text-xs text-[#c9b896] uppercase"><i class="ri-user-smile-line"></i> Autores dos Gols <span class="gc-hint">(adicione GC após o nome para gol contra)</span></label><div id="lista-gols" class="space-y-2"></div><button type="button" onclick="adicionarGol()" class="text-xs text-[#c9b896] mt-2"><i class="ri-add-circle-line"></i> Adicionar gol</button></div>
        <div><label class="text-xs text-[#c9b896] uppercase"><i class="ri-alert-line"></i> Cartões</label><div id="lista-cartoes" class="space-y-2"></div><button type="button" onclick="adicionarCartao()" class="text-xs text-[#c9b896] mt-2"><i class="ri-add-circle-line"></i> Adicionar cartão</button></div>
        <div class="flex justify-end gap-3 pt-3 border-t border-[#ffffff08]"><button type="button" onclick="fecharModal()" class="px-5 py-2.5 rounded-xl text-sm text-[#f8f7f4]/60">Cancelar</button><button type="submit" class="btn-primary px-6 py-2.5 rounded-xl text-sm"><i class="ri-save-line"></i> Salvar</button></div>
    </form></div></div>

    <footer class="border-t border-[#ffffff05] mt-12 py-6 text-center text-xs text-[#f8f7f4]/25 font-mono">COPA DO MUNDO FIFA 2026™ • <?=$totalFinalizadas?>/104 partidas finalizadas</footer>

    <script src="https://cdn.jsdelivr.net/npm/flowbite@4.0.1/dist/flowbite.min.js"></script>
    <script>
        const partidasData=<?=json_encode($partidas)?>;
        const estCidades=<?=json_encode($estadios_unicos)?>;
        const todosJogadores=<?=json_encode($listaJogadores)?>;
        let contadorGols=0,contadorCartoes=0;
        let faseAtualModal='';
        window.inputCounter=0;

        function alterarGols(campo,delta){const input=document.getElementById('input-'+campo);if(!input)return;let valor=parseInt(input.value)||0;valor=Math.max(0,Math.min(20,valor+delta));input.value=valor;atualizarBlocoPenaltis()}

        function atualizarBlocoPenaltis(){
            const bloco=document.getElementById('bloco-penaltis');if(!bloco)return;
            const g1=document.getElementById('input-gols1').value;
            const g2=document.getElementById('input-gols2').value;
            const empate=g1!==''&&g2!==''&&parseInt(g1)===parseInt(g2);
            const faseMataMata=faseAtualModal!==''&&faseAtualModal!=='grupos';
            const mostrar=faseMataMata&&empate;
            bloco.style.display=mostrar?'block':'none';
            // Sincronizar labels dos times
            const t1sel=document.getElementById('input-time1');
            const t2sel=document.getElementById('input-time2');
            const l1=document.getElementById('label-pen1');const l2=document.getElementById('label-pen2');
            if(l1&&t1sel)l1.textContent=t1sel.value||'Time 1';
            if(l2&&t2sel)l2.textContent=t2sel.value||'Time 2';
            // Se o bloco sumiu, limpar os campos para não enviar valores residuais
            if(!mostrar){limparPenaltis()}
        }

        function criarInputJogador(name,placeholder,value=''){const uniqueId='j-'+Date.now()+'-'+(++window.inputCounter);return`<div class="autocomplete-wrapper" style="position:relative;flex:1;"><input name="${name}" id="${uniqueId}" value="${value.replace(/"/g,'&quot;')}" placeholder="${placeholder}" autocomplete="off" oninput="showAutocomplete(this)" onfocus="showAutocomplete(this)" class="w-full bg-black border border-[#ffffff10] rounded-lg px-3 py-2 text-xs text-[#f8f7f4] placeholder-[#f8f7f4]/30 focus:outline-none focus:border-[#c9b896]/50"><div class="autocomplete-list" id="${uniqueId}-list" style="position:absolute;top:100%;left:0;right:0;z-index:9999;background:#1a1a1a;border:1px solid rgba(201,184,150,0.3);border-radius:8px;max-height:200px;overflow-y:auto;display:none;"></div></div>`}

        function showAutocomplete(input){document.querySelectorAll('.autocomplete-list').forEach(l=>{if(l.id!==input.id+'-list'){l.style.display='none';l.innerHTML=''}});const list=document.getElementById(input.id+'-list');if(!list)return;const filter=input.value.toLowerCase().trim();if(filter===''){list.style.display='none';list.innerHTML='';return}const matches=todosJogadores.filter(j=>j.toLowerCase().includes(filter));matches.sort((a,b)=>{const aL=a.toLowerCase(),bL=b.toLowerCase();if(aL===filter&&bL!==filter)return -1;if(aL!==filter&&bL===filter)return 1;if(aL.startsWith(filter)&&!bL.startsWith(filter))return -1;if(!aL.startsWith(filter)&&bL.startsWith(filter))return 1;return a.localeCompare(b)});if(matches.length===0){list.style.display='none';list.innerHTML='';return}list.innerHTML=matches.map((j,idx)=>`<div class="autocomplete-item" style="padding:8px 12px;cursor:pointer;font-size:0.75rem;color:#f8f7f4;border-bottom:1px solid rgba(255,255,255,0.05);${idx===0?'background:rgba(201,184,150,0.2);color:#c9b896;':''}" onmouseover="this.style.background='rgba(201,184,150,0.2)';this.style.color='#c9b896';" onmouseout="this.style.background='';this.style.color='#f8f7f4';" onmousedown="event.preventDefault();selectAutocomplete(this,'${input.id}')">${j}</div>`).join('');list.style.display='block'}

        function selectAutocomplete(item,inputId){const input=document.getElementById(inputId);if(!input)return;input.value=item.textContent.trim();input.focus();document.querySelectorAll('.autocomplete-list').forEach(l=>{l.style.display='none';l.innerHTML=''})}

        document.addEventListener('click',function(e){if(!e.target.closest('.autocomplete-wrapper')){document.querySelectorAll('.autocomplete-list').forEach(l=>{l.style.display='none';l.innerHTML=''})}});
        document.addEventListener('keydown',function(e){if(e.key==='Escape'){document.querySelectorAll('.autocomplete-list').forEach(l=>{l.style.display='none';l.innerHTML=''})}});

        function adicionarGol(jogador='',minuto='',time='casa'){contadorGols++;const tc=document.getElementById('input-time1')?.value||'Time 1';const tf=document.getElementById('input-time2')?.value||'Time 2';document.getElementById('lista-gols').insertAdjacentHTML('beforeend',`<div class="flex gap-2 items-center" id="gol-${contadorGols}">${criarInputJogador('jogador[]','Nome do jogador (GC)',jogador)}<input name="minuto[]" value="${minuto}" placeholder="Ex: 45+2" class="w-20 bg-black border border-[#ffffff10] rounded-lg px-2 py-2 text-xs text-center"><select name="time_evento[]" class="bg-black border border-[#ffffff10] rounded-lg px-2 py-2 text-xs"><option value="casa" ${time==='casa'?'selected':''}>${tc}</option><option value="fora" ${time==='fora'?'selected':''}>${tf}</option></select><button type="button" onclick="this.parentElement.remove()" class="text-red-400 hover:text-red-300"><i class="ri-close-circle-line"></i></button></div>`)}

        function adicionarCartao(jogador='',minuto='',tipo='amarelo',time='casa'){contadorCartoes++;const tc=document.getElementById('input-time1')?.value||'Time 1';const tf=document.getElementById('input-time2')?.value||'Time 2';document.getElementById('lista-cartoes').insertAdjacentHTML('beforeend',`<div class="flex gap-2 items-center" id="cartao-${contadorCartoes}">${criarInputJogador('jogador_cartao[]','Jogador',jogador)}<input name="minuto_cartao[]" value="${minuto}" placeholder="Ex: 45+2" class="w-20 bg-black border border-[#ffffff10] rounded-lg px-2 py-2 text-xs text-center"><select name="tipo_cartao[]" class="bg-black border border-[#ffffff10] rounded-lg px-2 py-2 text-xs"><option value="amarelo" ${tipo==='amarelo'?'selected':''}>Amarelo</option><option value="vermelho" ${tipo==='vermelho'?'selected':''}>Vermelho</option></select><select name="time_cartao[]" class="bg-black border border-[#ffffff10] rounded-lg px-2 py-2 text-xs"><option value="casa" ${time==='casa'?'selected':''}>${tc}</option><option value="fora" ${time==='fora'?'selected':''}>${tf}</option></select><button type="button" onclick="this.parentElement.remove()" class="text-red-400 hover:text-red-300"><i class="ri-close-circle-line"></i></button></div>`)}

        function abrirModalEdicao(partidaId){
            const partida=partidasData.find(p=>p.id===partidaId);
            if(!partida)return;
            faseAtualModal=partida.fase;
            document.getElementById('input-partida-id').value=partida.id;
            document.getElementById('input-time1').value=partida.time1;
            document.getElementById('input-time2').value=partida.time2;
            document.getElementById('input-gols1').value=partida.gols1??0;
            document.getElementById('input-gols2').value=partida.gols2??0;
            document.getElementById('input-data').value=partida.data;
            document.getElementById('input-horario').value=partida.horario;
            document.getElementById('input-estadio').value=partida.estadio;
            document.getElementById('input-cidade').value=partida.cidade;
            document.getElementById('input-status').value=partida.status;
            // Labels dos times dentro do bloco de pênaltis
            const t1=partida.time1==='A definir'?'Time 1':partida.time1;
            const t2=partida.time2==='A definir'?'Time 2':partida.time2;
            const l1=document.getElementById('label-pen1');const l2=document.getElementById('label-pen2');
            if(l1)l1.textContent=t1;if(l2)l2.textContent=t2;
            // Carregar pênaltis (null → string vazia para exibir placeholder)
            const p1=document.getElementById('input-penaltis1');
            const p2=document.getElementById('input-penaltis2');
            if(p1)p1.value=partida.penaltis1??'';
            if(p2)p2.value=partida.penaltis2??'';
            // Travar/destravar seleção (Rodada de 32)
            const blocoManual=document.getElementById('bloco-manual');
            const inputManual=document.getElementById('input-manual');
            if(partida.fase==='16avos'){blocoManual.style.display='block';inputManual.checked=!!partida.manual}
            else{blocoManual.style.display='none';inputManual.checked=false}
            // Mostrar/ocultar bloco de pênaltis conforme fase e placar
            atualizarBlocoPenaltis();
            // Gols e cartões
            document.getElementById('lista-gols').innerHTML='';contadorGols=0;
            if(partida.eventos)partida.eventos.forEach(e=>adicionarGol(e.jogador,e.minuto,e.time));
            document.getElementById('lista-cartoes').innerHTML='';contadorCartoes=0;
            if(partida.cartoes)partida.cartoes.forEach(c=>adicionarCartao(c.jogador,c.minuto,c.tipo,c.time));
            atualizarCidade();
            document.getElementById('modal-backdrop').classList.remove('hidden');
            document.body.style.overflow='hidden';
        }

        function limparPenaltis(){const p1=document.getElementById('input-penaltis1');const p2=document.getElementById('input-penaltis2');if(p1)p1.value='';if(p2)p2.value=''}

        function fecharModal(event){if(event&&event.target!==document.getElementById('modal-backdrop'))return;document.getElementById('modal-backdrop').classList.add('hidden');document.body.style.overflow=''}

        document.addEventListener('keydown',e=>{if(e.key==='Escape')fecharModal()});
        const inputEstadio=document.getElementById('input-estadio');if(inputEstadio)inputEstadio.addEventListener('change',atualizarCidade);
        function atualizarCidade(){const est=document.getElementById('input-estadio')?.value;if(est)document.getElementById('input-cidade').value=estCidades[est]||''}

        <?php if(isset($_GET['editar'])&&is_numeric($_GET['editar'])):?>setTimeout(()=>abrirModalEdicao(<?=(int)$_GET['editar']?>),300);<?php endif;?>

        function filtrarConteudo(valor){const q=valor.toLowerCase().trim();document.querySelectorAll('.card,.timeline-day,.chave-card,.match-history').forEach(el=>{if(el.closest('#modal-backdrop'))return;el.classList.toggle('search-hidden',q!==''&&!el.textContent.toLowerCase().includes(q))})}
        const formEdicao=document.getElementById('form-edicao');
        if(formEdicao){formEdicao.addEventListener('submit',()=>{let i=document.getElementById('save-indicator');if(i){i.style.display='block';i.innerHTML='<i class="ri-loader-4-line"></i> Salvando...'}});formEdicao.addEventListener('keydown',e=>{if(e.key==='Enter'&&e.target.tagName!=='TEXTAREA'&&e.target.type!=='submit'){e.preventDefault();if(e.target.name==='minuto[]')adicionarGol()}})}
        document.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='s'){const modal=document.getElementById('modal-backdrop');if(modal&&!modal.classList.contains('hidden')){e.preventDefault();document.getElementById('form-edicao').requestSubmit()}}});
        document.querySelectorAll('.chave-card').forEach((card,i)=>{card.style.animationDelay=(i*18)+'ms';card.classList.add('bracket-enter')});

    </script>
<div id="save-indicator" class="save-indicator"></div>
</body>
</html>
