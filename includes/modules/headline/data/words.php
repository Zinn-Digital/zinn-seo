<?php
/**
 * The headline analyser's word lists, per language (data only — zs-715).
 *
 * `common`: the short words every sentence needs (articles, pronouns, prepositions). A headline
 * made only of them says nothing; one with none of them reads like a list of keywords.
 * `emotional`: words that make a reader feel something (fear, joy, surprise, trust).
 * `power`: words copywriters use to make a promise or create urgency.
 * `how`: how a "how to" headline starts; `question`: the words a question starts with.
 *
 * Lower case, as typed. A language not listed here is scored on length, numbers and form only,
 * and the screen says so — it never pretends to judge words it cannot read.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'en' => array(
		'common'    => array( 'a', 'an', 'the', 'and', 'or', 'but', 'of', 'to', 'in', 'on', 'at', 'for', 'with', 'by', 'from', 'up', 'about', 'into', 'over', 'after', 'is', 'are', 'was', 'were', 'be', 'been', 'it', 'its', 'this', 'that', 'these', 'those', 'i', 'you', 'your', 'we', 'our', 'they', 'their', 'he', 'she', 'his', 'her', 'my', 'me', 'us', 'them', 'what', 'which', 'who', 'when', 'where', 'why', 'how', 'all', 'more', 'most', 'can', 'will', 'do', 'does', 'not', 'no', 'so', 'if', 'than', 'then', 'just', 'get', 'make', 'new', 'one', 'out', 'as', 'have', 'has' ),
		'emotional' => array( 'amazing', 'astonishing', 'beautiful', 'brilliant', 'heartbreaking', 'shocking', 'surprising', 'unbelievable', 'incredible', 'terrifying', 'frightening', 'scary', 'mistake', 'mistakes', 'fail', 'failure', 'love', 'hate', 'happy', 'sad', 'angry', 'fear', 'worst', 'best', 'stunning', 'jaw-dropping', 'inspiring', 'heartwarming', 'hilarious', 'painful', 'dangerous', 'deadly', 'risky', 'warning', 'secret', 'secrets', 'hidden', 'banned', 'forbidden', 'lonely', 'proud', 'joy', 'delight', 'remarkable', 'magical', 'wonderful', 'awful', 'disaster', 'crisis', 'regret', 'trust', 'beloved', 'brave', 'bold', 'devastating', 'weird', 'strange', 'odd', 'tragic', 'thrilling', 'exciting', 'mind-blowing', 'unforgettable', 'emotional', 'wow' ),
		'power'     => array( 'free', 'instant', 'instantly', 'now', 'today', 'proven', 'guaranteed', 'ultimate', 'essential', 'complete', 'definitive', 'easy', 'simple', 'quick', 'fast', 'effortless', 'exclusive', 'limited', 'save', 'boost', 'master', 'unlock', 'discover', 'reveal', 'revealed', 'insider', 'expert', 'step-by-step', 'guide', 'tips', 'tricks', 'hacks', 'powerful', 'effective', 'results', 'never', 'always', 'everything', 'nothing', 'must', 'need', 'stop', 'avoid', 'win', 'grow', 'double', 'triple', 'skyrocket', 'transform', 'beginner', 'beginners', 'checklist', 'blueprint', 'formula', 'strategy', 'strategies', 'perfect', 'top', 'killer', 'smart', 'new', 'latest', 'updated', 'without' ),
		'how'       => array( 'how to', 'how do', 'how can', 'how i', 'how we' ),
		'question'  => array( 'what', 'why', 'how', 'when', 'where', 'who', 'which', 'is', 'are', 'can', 'should', 'do', 'does', 'will' ),
	),
	'de' => array(
		'common'    => array( 'der', 'die', 'das', 'ein', 'eine', 'einen', 'einem', 'und', 'oder', 'aber', 'von', 'zu', 'in', 'im', 'an', 'am', 'auf', 'für', 'mit', 'bei', 'aus', 'nach', 'über', 'ist', 'sind', 'war', 'wird', 'es', 'sie', 'er', 'wir', 'ihr', 'ich', 'du', 'sich', 'nicht', 'kein', 'so', 'wie', 'was', 'wer', 'wann', 'wo', 'warum', 'den', 'dem', 'des', 'zum', 'zur', 'als', 'auch', 'noch', 'nur', 'mehr', 'dein', 'ihre', 'unser', 'alle', 'man' ),
		'emotional' => array( 'unglaublich', 'erstaunlich', 'schockierend', 'überraschend', 'fantastisch', 'traurig', 'glücklich', 'gefährlich', 'tödlich', 'warnung', 'geheim', 'geheimnis', 'geheimnisse', 'verboten', 'fehler', 'peinlich', 'schrecklich', 'wunderbar', 'atemberaubend', 'herzzerreißend', 'liebe', 'hass', 'angst', 'beste', 'schlimmste', 'genial', 'verrückt', 'seltsam', 'mutig', 'stolz', 'katastrophe', 'krise', 'bereuen', 'vertrauen', 'spannend', 'traumhaft' ),
		'power'     => array( 'kostenlos', 'gratis', 'sofort', 'jetzt', 'heute', 'bewährt', 'garantiert', 'ultimativ', 'komplett', 'einfach', 'schnell', 'mühelos', 'exklusiv', 'begrenzt', 'sparen', 'meistern', 'entdecken', 'enthüllt', 'experte', 'experten', 'anleitung', 'schritt-für-schritt', 'tipps', 'tricks', 'wirksam', 'ergebnisse', 'nie', 'immer', 'alles', 'muss', 'vermeiden', 'gewinnen', 'wachsen', 'verdoppeln', 'checkliste', 'strategie', 'perfekt', 'top', 'neu', 'neue', 'aktuell', 'ohne' ),
		'how'       => array( 'wie man', 'so geht', 'so gelingt', 'so funktioniert', 'wie sie', 'wie du' ),
		'question'  => array( 'was', 'warum', 'wie', 'wann', 'wo', 'wer', 'welche', 'welcher', 'welches', 'ist', 'sind', 'kann', 'sollte', 'soll' ),
	),
	'fr' => array(
		'common'    => array( 'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'd', 'l', 'et', 'ou', 'mais', 'à', 'au', 'aux', 'en', 'dans', 'sur', 'pour', 'par', 'avec', 'sans', 'est', 'sont', 'il', 'elle', 'ils', 'elles', 'on', 'nous', 'vous', 'je', 'tu', 'ce', 'cette', 'ces', 'son', 'sa', 'ses', 'votre', 'vos', 'notre', 'nos', 'qui', 'que', 'quoi', 'ne', 'pas', 'plus', 'tout', 'tous', 'comme', 'se', 'y' ),
		'emotional' => array( 'incroyable', 'étonnant', 'choquant', 'surprenant', 'magnifique', 'triste', 'heureux', 'dangereux', 'mortel', 'attention', 'secret', 'secrets', 'caché', 'interdit', 'erreur', 'erreurs', 'terrible', 'merveilleux', 'bouleversant', 'amour', 'haine', 'peur', 'meilleur', 'meilleure', 'pire', 'génial', 'fou', 'étrange', 'courageux', 'fier', 'catastrophe', 'crise', 'regret', 'confiance', 'passionnant', 'inoubliable' ),
		'power'     => array( 'gratuit', 'gratuite', 'instantané', 'maintenant', 'aujourd\'hui', 'prouvé', 'garanti', 'ultime', 'essentiel', 'complet', 'facile', 'simple', 'rapide', 'exclusif', 'limité', 'économiser', 'maîtriser', 'découvrir', 'révélé', 'expert', 'guide', 'astuces', 'conseils', 'efficace', 'résultats', 'jamais', 'toujours', 'tout', 'doit', 'éviter', 'gagner', 'doubler', 'stratégie', 'parfait', 'top', 'nouveau', 'nouvelle', 'sans' ),
		'how'       => array( 'comment', 'comment faire' ),
		'question'  => array( 'quoi', 'pourquoi', 'comment', 'quand', 'où', 'qui', 'quel', 'quelle', 'quels', 'quelles', 'est-ce', 'faut-il', 'peut-on' ),
	),
	'es' => array(
		'common'    => array( 'el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas', 'y', 'o', 'pero', 'de', 'del', 'a', 'al', 'en', 'con', 'por', 'para', 'sin', 'sobre', 'es', 'son', 'era', 'ser', 'lo', 'le', 'se', 'su', 'sus', 'tu', 'tus', 'mi', 'mis', 'nuestro', 'que', 'qué', 'como', 'no', 'más', 'muy', 'todo', 'todos', 'este', 'esta', 'estos', 'estas', 'yo', 'usted', 'ellos' ),
		'emotional' => array( 'increíble', 'asombroso', 'impactante', 'sorprendente', 'hermoso', 'triste', 'feliz', 'peligroso', 'mortal', 'advertencia', 'secreto', 'secretos', 'oculto', 'prohibido', 'error', 'errores', 'terrible', 'maravilloso', 'desgarrador', 'amor', 'odio', 'miedo', 'mejor', 'peor', 'genial', 'loco', 'extraño', 'valiente', 'orgulloso', 'desastre', 'crisis', 'confianza', 'emocionante', 'inolvidable' ),
		'power'     => array( 'gratis', 'gratuito', 'instantáneo', 'ahora', 'hoy', 'probado', 'garantizado', 'definitivo', 'esencial', 'completo', 'fácil', 'sencillo', 'rápido', 'exclusivo', 'limitado', 'ahorrar', 'dominar', 'descubre', 'descubrir', 'revelado', 'experto', 'guía', 'trucos', 'consejos', 'eficaz', 'resultados', 'nunca', 'siempre', 'todo', 'debe', 'evitar', 'ganar', 'duplicar', 'estrategia', 'perfecto', 'top', 'nuevo', 'nueva' ),
		'how'       => array( 'cómo', 'como hacer', 'cómo hacer' ),
		'question'  => array( 'qué', 'por qué', 'cómo', 'cuándo', 'dónde', 'quién', 'cuál', 'cuáles', 'es', 'son', 'puedo', 'debo' ),
	),
	'it' => array(
		'common'    => array( 'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'uno', 'una', 'e', 'o', 'ma', 'di', 'del', 'della', 'dei', 'a', 'al', 'alla', 'in', 'nel', 'nella', 'con', 'su', 'per', 'da', 'tra', 'è', 'sono', 'era', 'si', 'non', 'che', 'chi', 'come', 'più', 'tutto', 'tutti', 'questo', 'questa', 'tuo', 'tua', 'nostro', 'loro', 'io', 'tu', 'noi', 'voi' ),
		'emotional' => array( 'incredibile', 'sorprendente', 'scioccante', 'bellissimo', 'triste', 'felice', 'pericoloso', 'mortale', 'attenzione', 'segreto', 'segreti', 'nascosto', 'vietato', 'errore', 'errori', 'terribile', 'meraviglioso', 'straziante', 'amore', 'odio', 'paura', 'migliore', 'peggiore', 'geniale', 'pazzesco', 'strano', 'coraggioso', 'orgoglioso', 'disastro', 'crisi', 'fiducia', 'emozionante', 'indimenticabile' ),
		'power'     => array( 'gratis', 'gratuito', 'istantaneo', 'ora', 'adesso', 'oggi', 'provato', 'garantito', 'definitivo', 'essenziale', 'completo', 'facile', 'semplice', 'veloce', 'rapido', 'esclusivo', 'limitato', 'risparmiare', 'scopri', 'scoprire', 'rivelato', 'esperto', 'guida', 'trucchi', 'consigli', 'efficace', 'risultati', 'mai', 'sempre', 'tutto', 'deve', 'evitare', 'vincere', 'raddoppiare', 'strategia', 'perfetto', 'top', 'nuovo', 'nuova' ),
		'how'       => array( 'come', 'come fare' ),
		'question'  => array( 'cosa', 'perché', 'come', 'quando', 'dove', 'chi', 'quale', 'quali', 'è', 'sono', 'posso', 'devo' ),
	),
	'pt' => array(
		'common'    => array( 'o', 'a', 'os', 'as', 'um', 'uma', 'uns', 'umas', 'e', 'ou', 'mas', 'de', 'do', 'da', 'dos', 'das', 'em', 'no', 'na', 'nos', 'nas', 'com', 'por', 'para', 'sem', 'sobre', 'é', 'são', 'era', 'ser', 'se', 'seu', 'sua', 'seus', 'suas', 'que', 'quem', 'como', 'não', 'mais', 'muito', 'tudo', 'todos', 'este', 'esta', 'isso', 'eu', 'você', 'nós', 'eles' ),
		'emotional' => array( 'incrível', 'surpreendente', 'chocante', 'lindo', 'triste', 'feliz', 'perigoso', 'mortal', 'atenção', 'segredo', 'segredos', 'escondido', 'proibido', 'erro', 'erros', 'terrível', 'maravilhoso', 'amor', 'ódio', 'medo', 'melhor', 'pior', 'genial', 'louco', 'estranho', 'corajoso', 'orgulhoso', 'desastre', 'crise', 'confiança', 'emocionante', 'inesquecível' ),
		'power'     => array( 'grátis', 'gratuito', 'instantâneo', 'agora', 'hoje', 'comprovado', 'garantido', 'definitivo', 'essencial', 'completo', 'fácil', 'simples', 'rápido', 'exclusivo', 'limitado', 'economizar', 'dominar', 'descubra', 'descobrir', 'revelado', 'especialista', 'guia', 'truques', 'dicas', 'eficaz', 'resultados', 'nunca', 'sempre', 'tudo', 'deve', 'evitar', 'ganhar', 'dobrar', 'estratégia', 'perfeito', 'top', 'novo', 'nova' ),
		'how'       => array( 'como', 'como fazer' ),
		'question'  => array( 'o que', 'por que', 'porque', 'como', 'quando', 'onde', 'quem', 'qual', 'quais', 'é', 'são', 'posso', 'devo' ),
	),
	'nl' => array(
		'common'    => array( 'de', 'het', 'een', 'en', 'of', 'maar', 'van', 'naar', 'in', 'op', 'aan', 'voor', 'met', 'bij', 'uit', 'over', 'door', 'is', 'zijn', 'was', 'wordt', 'het', 'ze', 'zij', 'hij', 'wij', 'we', 'je', 'jij', 'u', 'uw', 'ons', 'onze', 'niet', 'geen', 'zo', 'hoe', 'wat', 'wie', 'wanneer', 'waar', 'waarom', 'als', 'ook', 'nog', 'meer', 'alle', 'dit', 'dat', 'die' ),
		'emotional' => array( 'ongelooflijk', 'verbazingwekkend', 'schokkend', 'verrassend', 'prachtig', 'verdrietig', 'gelukkig', 'gevaarlijk', 'dodelijk', 'waarschuwing', 'geheim', 'geheimen', 'verborgen', 'verboden', 'fout', 'fouten', 'vreselijk', 'geweldig', 'hartverscheurend', 'liefde', 'haat', 'angst', 'beste', 'slechtste', 'geniaal', 'gek', 'vreemd', 'moedig', 'trots', 'ramp', 'crisis', 'vertrouwen', 'spannend', 'onvergetelijk' ),
		'power'     => array( 'gratis', 'direct', 'nu', 'vandaag', 'bewezen', 'gegarandeerd', 'ultiem', 'essentieel', 'compleet', 'makkelijk', 'eenvoudig', 'snel', 'moeiteloos', 'exclusief', 'beperkt', 'besparen', 'ontdek', 'ontdekken', 'onthuld', 'expert', 'gids', 'tips', 'trucs', 'effectief', 'resultaten', 'nooit', 'altijd', 'alles', 'moet', 'vermijden', 'winnen', 'verdubbelen', 'strategie', 'perfect', 'top', 'nieuw', 'nieuwe', 'zonder' ),
		'how'       => array( 'hoe je', 'hoe u', 'zo maak', 'zo werkt' ),
		'question'  => array( 'wat', 'waarom', 'hoe', 'wanneer', 'waar', 'wie', 'welke', 'welk', 'is', 'zijn', 'kan', 'moet' ),
	),
);
