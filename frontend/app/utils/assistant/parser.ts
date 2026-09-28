/**
 * Voice assistant of a cleaning: turns a spoken sentence (French) into a command. Pure module, no Nuxt, no DOM:
 * unit-tested with `node --test` (frontend/tests/assistant.test.ts).
 *
 * Same approach as Doc Assist's surgery assistant (backend/src/Assistant in doc-assist: TextNormalizer,
 * VoiceCommandParser, ActMatcher), ported to TypeScript so that it also works offline on the phone:
 * - text normalised (lower case, no accents, no punctuation), 6-letter word stems without stop words;
 * - explicit commands first (whole sentence: "suivant", "répète", "terminer"…), then "signaler …", stock
 *   phrases, then free speech compared with the checklist (label + synonyms), with a stricter threshold when no
 *   "j'ai fini …" / "… c'est fait" tells that a point is being talked about;
 * - quantities in words or digits ("deux rouleaux", "3 capsules").
 */

export type StockLevel = 'ok' | 'low' | 'empty'

export interface MatchableItem {
  label: string
  synonyms?: string[]
}

export interface StockItem { id: string, name: string, level: StockLevel, synonyms?: string[] }

export type Command =
  | { kind: 'next' | 'previous' | 'skip' | 'repeat' | 'pause' | 'resume' | 'where' | 'remaining' | 'finish' | 'help' | 'yes' | 'no' | 'photo' }
  | { kind: 'check', index: number, label: string, score: number }
  | { kind: 'stock', id: string, name: string, level: StockLevel, quantity: number | null }
  | { kind: 'incident', text: string }
  | { kind: 'unknown', text: string }

/** Score from which a checklist point is taken: introduced ("j'ai fini …", "… c'est fait"), or said alone. */
export const EXPLICIT_THRESHOLD = 0.45
export const IMPLICIT_THRESHOLD = 0.7
export const STOCK_THRESHOLD = 0.45

const STOP_WORDS = new Set(['a', 'au', 'aux', 'avec', 'ce', 'cet', 'cette', 'd', 'dans', 'de', 'des', 'du', 'en', 'et', 'l', 'la', 'le', 'les',
  'on', 'ou', 'par', 'pour', 'sans', 'se', 'sous', 'sur', 'un', 'une', 'y', 'je', 'j', 'nous', 'fait', 'faite', 'faits', 'faites', 'fini', 'finie',
  'finis', 'termine', 'terminee', 'maintenant', 'alors', 'donc', 'voila', 'ok', 'bon', 'bien', 'tres', 'est', 'sont', 'c', 'qu', 'que', 'qui',
  'il', 'elle', 'ai', 'avons', 'suis', 'va', 'vais', 'mon', 'ma', 'mes', 'ton', 'ta', 'tes', 'son', 'sa', 'ses', 'toute', 'tout', 'tous', 'toutes'])

const NUMBERS: Record<string, number> = { un: 1, une: 1, deux: 2, trois: 3, quatre: 4, cinq: 5, six: 6, sept: 7, huit: 8, neuf: 9, dix: 10,
  onze: 11, douze: 12, quinze: 15, vingt: 20 }

/** Usual names of cleaning supplies, added to the synonyms of a stock item whose name contains the key. */
export const STOCK_SYNONYMS: Record<string, string[]> = {
  'papier toilette': ['pq', 'papier wc', 'papier hygienique', 'papier toilettes', 'rouleau', 'rouleaux'],
  'cafe': ['capsule', 'capsules', 'dosette', 'dosettes', 'cafe moulu'],
  'liquide vaisselle': ['produit vaisselle', 'savon vaisselle', 'produit a vaisselle', 'liquide a vaisselle'],
  'lave vaisselle': ['pastille', 'pastilles', 'tablette', 'tablettes'],
  'sac poubelle': ['sacs poubelle', 'sac', 'sacs', 'sacs a ordures'],
  'essuie tout': ['sopalin', 'essuie-tout'],
  'eponge': ['eponges'],
  'savon': ['savon main', 'savon mains', 'savon liquide'],
  'gel douche': ['savon douche'],
  'shampo': ['shampoing', 'shampooing', 'shampoin'],
  'lessive': ['produit lessive'],
  'the': ['sachets de the', 'infusion'],
  'sucre': ['morceaux de sucre'],
}

/** "Salle de Bain !" → "salle de bain"; "œuf" → "oeuf". */
export function normalize(text: string): string {
  return text.toLowerCase()
    .normalize('NFD').replace(/\p{Mn}+/gu, '')
    .replace(/œ/g, 'oe').replace(/æ/g, 'ae')
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
}

/** Meaningful word stems (6 first letters: "rouleau" and "rouleaux", "nettoyer" and "nettoyé" meet). */
export function stems(text: string): string[] {
  const out = new Set<string>()
  for (const word of normalize(text).split(' ')) {
    if (!word || (word.length < 3 && !/^\d+$/.test(word) && word !== 'pq') || STOP_WORDS.has(word)) continue
    out.add(word.slice(0, 6))
  }
  return [...out]
}

function numberValue(word: string): number | null {
  if (/^\d{1,3}$/.test(word)) return Number(word)
  return NUMBERS[word] ?? null
}

/** "deux rouleaux de papier" → [2, "rouleaux de papier"]; a leading "un"/"une" is an article unless $articles. */
export function quantity(normalized: string, articles = false): [number | null, string] {
  const words = normalized.split(' ')
  const first = words[0] ?? ''
  const value = numberValue(first)
  if (value !== null && words.length > 1 && (articles || (first !== 'un' && first !== 'une'))) return [value, words.slice(1).join(' ')]
  return [null, normalized]
}

/** Best items a sentence talks about, best first (score 0…1), as in Doc Assist's ActMatcher. */
export function match(sentence: string, items: MatchableItem[], limit = 3): { index: number, score: number }[] {
  const normalized = ` ${normalize(sentence)} `
  const said = stems(sentence)
  if (!said.length) return []
  const found: { index: number, score: number }[] = []
  items.forEach((item, index) => {
    let score = 0
    for (const phrase of [item.label, ...(item.synonyms ?? [])].map(normalize).filter(Boolean)) {
      if (normalized.includes(` ${phrase} `)) {
        // Longer phrases are more specific: "salle de bain" beats "bain".
        score = Math.max(score, Math.min(1, 0.8 + 0.05 * (phrase.split(' ').length - 1)))
      }
    }
    const label = stems(item.label)
    const hits = label.filter(s => said.includes(s)).length
    if (hits > 0 && (hits >= 2 || label.length === 1 || said.length === 1)) {
      const recall = hits / label.length
      const precision = hits / said.length
      score = Math.max(score, 0.9 * 2 * recall * precision / (recall + precision))
    }
    if (score > 0) found.push({ index, score })
  })
  found.sort((a, b) => b.score - a.score || items[b.index]!.label.length - items[a.index]!.label.length)
  return found.slice(0, limit)
}

/** Synonyms of a stock item: its own, plus the usual names (STOCK_SYNONYMS) of what its name contains. */
export function stockSynonyms(item: StockItem): string[] {
  const name = ` ${normalize(item.name)} `
  const extra = Object.entries(STOCK_SYNONYMS).filter(([key]) => name.includes(` ${key}`)).flatMap(([, list]) => list)
  return [...(item.synonyms ?? []), ...extra]
}

// Whole-sentence commands (after normalize). Order matters: the first match wins.
const COMMANDS: [Exclude<Command['kind'], 'check' | 'stock' | 'incident' | 'unknown'>, RegExp][] = [
  ['yes', /^(oui|ouais|oui oui|d accord|daccord|confirme|confirmer|exact|exactement|c est ca|correct|vas y|valide oui)$/],
  ['no', /^(non|non non|annule|annuler|pas ca|c est pas ca|ce n est pas ca|laisse tomber|non merci)$/],
  ['next', /^(suivant|suivante|point suivant|etape suivante|c est fait|cest fait|fait|valider|valide|validee|ok c est fait|c est bon|termine ce point|fini|c est fini|ok suivant|next)$/],
  ['previous', /^(precedent|precedente|point precedent|etape precedente|retour|reviens|recule|revenir en arriere)$/],
  ['skip', /^(passer|passe|je passe|sauter|saute|on passe|plus tard|ignorer|ignore)$/],
  ['repeat', /^(repete|repeter|repetez|tu peux repeter|peux tu repeter|encore|redis|redis le|quoi|pardon|comment)$/],
  ['pause', /^(pause|stop|attends|attend|arrete|arreter|silence|tais toi|chut)$/],
  ['resume', /^(reprendre|reprends|reprise|on reprend|continue|continuer|on continue|c est reparti)$/],
  ['where', /^(ou j en suis|ou en suis je|on en est ou|ou on en est|j en suis ou|ou est ce que j en suis|point actuel|etape actuelle)$/],
  ['remaining', /^(combien (il )?(en )?reste( t il)?( de points)?|il (en )?reste combien( de points)?|combien de points( restent| reste)?|il reste quoi|qu est ce qui reste|ce qui reste)$/],
  ['finish', /^(terminer|termine|terminer le menage|j ai termine|j ai fini|c est termine|fin du menage|menage termine|le menage est (fini|termine)|j ai fini le menage|j ai termine le menage|on a fini|fin)$/],
  ['photo', /^(photo|prendre (une )?photo|prends (une )?photo|appareil photo|camera)$/],
  ['help', /^(aide|au secours|qu est ce que je peux dire|que puis je dire|commandes|quelles commandes)$/],
]

const INCIDENT = /^(?:(?:signaler|signale|signalement|je signale|il y a|y a|j ai)\s+)?(?:un |une )?(?:probleme|souci|incident|degat|degats|casse|panne)(?:\s+(?:avec|sur|dans|de|du|a|au|que|c est|est))?\s+(?<rest>.+)$/

// Stock: level and the rest of the sentence naming the item.
const STOCK_PATTERNS: [StockLevel, RegExp][] = [
  ['empty', /^(?:il (?:n )?y a plus|y a plus|il n y a plus|on (?:n )?a plus|(?:il )?n y a plus|plus|rupture|rupture de stock)\s+(?:de |d |du |des )?(?<rest>.+)$/],
  ['empty', /^(?:il (?:me |nous )?manque|manque|il faut racheter|il faut acheter|a racheter|racheter)\s+(?:de |d |du |de la |des |le |la |les |l )?(?<rest>.+)$/],
  ['low', /^(?:il (?:ne )?reste (?:plus )?(?:tres )?peu|presque plus|bientot plus|peu)\s+(?:de |d |du |des )?(?<rest>.+)$/],
  ['low', /^(?:le |la |les |l )?(?<rest>.+?)\s+(?:est|sont)?\s*(?:bas|basse|bas bas|presque vide|presque vides|presque fini|presque finie|bientot fini|bientot vide|en train de finir|faible)$/],
  ['empty', /^(?:le |la |les |l )?(?<rest>.+?)\s+(?:est|sont)?\s*(?:vide|vides|fini|finie|finis|finies|epuise|epuisee|epuises|epuisees)$/],
  ['ok', /^(?:le |la |les |l )?(?<rest>.+?)\s+(?:est|sont)?\s*(?:plein|pleine|pleins|pleines|ok|au complet|rempli|remplie|remplis)$/],
]
const CONSUMED = /^(?:j ai|on a)?\s*(?:utilise|utilisee|utilises|pris|consomme|consommee|mis|change|remplace|remis|sorti)\s+(?<rest>.+)$/

/**
 * One spoken sentence → a command. $checklist: the cleaning's points (label + synonyms); $stock: the place's stock
 * items (name, level). A point or an item not recognised gives "unknown".
 */
export function parseCommand(transcript: string, checklist: MatchableItem[], stock: StockItem[] = []): Command {
  const text = transcript.trim()
  // "Assistant, …" / "OK Clean, …": the wake word is optional.
  const normalized = normalize(text).replace(/^(ok |dis |hey |he )?(assistant|clean|rocket)\s+/, '').replace(/\s+(s il (te|vous) plait|merci)$/, '')
  if (!normalized) return { kind: 'unknown', text }

  for (const [kind, pattern] of COMMANDS) {
    if (pattern.test(normalized)) return { kind }
  }

  const incident = INCIDENT.exec(normalized)
  if (incident?.groups?.rest && stems(incident.groups.rest).length) return { kind: 'incident', text: original(text, incident.groups.rest) }

  if (stock.length) {
    const found = parseStock(normalized, stock)
    if (found) return found
  }

  // A point of the checklist: "j'ai fini la salle de bain", "salle de bain faite", or just "salle de bain".
  const intro = /^(?:j ai|on a|c est|ca y est)?\s*(?:fini|finis|termine|terminee|fait|nettoye|nettoyee|lave|lavee|passe|range|rangee|change|changes|refait|vide|vidange|aspire|sorti|sortie|sortis)\s+(?:de |d |avec |le |la |les |l |du |des )?(?<rest>.+)$/.exec(normalized)
    ?? /^(?<rest>.+?)\s+(?:c est )?(?:est |sont )?(?:fait|faite|faits|faites|fini|finie|finis|finies|termine|terminee|termines|terminees|ok|propre|propres|nettoye|nettoyee|nettoyes|nettoyees|valide|validee|bon)$/.exec(normalized)
  const said = intro?.groups?.rest ?? normalized
  const best = match(said, checklist)[0]
  if (best && best.score >= (intro ? EXPLICIT_THRESHOLD : IMPLICIT_THRESHOLD)) {
    return { kind: 'check', index: best.index, label: checklist[best.index]!.label, score: best.score }
  }
  return { kind: 'unknown', text }
}

function parseStock(normalized: string, stock: StockItem[]): Command | null {
  const items = stock.map(s => ({ label: s.name, synonyms: stockSynonyms(s) }))
  const find = (rest: string) => {
    const best = match(rest, items)[0]
    return best && best.score >= STOCK_THRESHOLD ? stock[best.index]! : null
  }
  const consumed = CONSUMED.exec(normalized)
  if (consumed?.groups?.rest) {
    const [qty, rest] = quantity(consumed.groups.rest, true)
    const item = find(rest)
    if (item) return { kind: 'stock', id: item.id, name: item.name, level: item.level, quantity: qty ?? 1 }
  }
  for (const [level, pattern] of STOCK_PATTERNS) {
    const m = pattern.exec(normalized)
    if (!m?.groups?.rest) continue
    const [qty, rest] = quantity(m.groups.rest)
    const item = find(rest)
    if (item) return { kind: 'stock', id: item.id, name: item.name, level, quantity: qty }
  }
  return null
}

/** The end of the sentence as said (accents and case kept) when it can be found, else the normalised one. */
function original(text: string, normalizedRest: string): string {
  const words = text.split(/\s+/)
  for (let n = 1; n <= words.length; n++) {
    const tail = words.slice(-n).join(' ')
    if (normalize(tail) === normalizedRest) return tail.replace(/^[\s:,-]+|[\s.]+$/g, '')
  }
  return normalizedRest
}

// ——— Step tracking ———

export interface Step { label: string, done: boolean, photo?: boolean, area?: string }

/** Index of the next point not done from $from (wrapping around), or -1 when all are done. */
export function nextOpen(checklist: Step[], from: number): number {
  const n = checklist.length
  for (let i = 0; i < n; i++) {
    const index = (from + i) % n
    if (!checklist[index]!.done) return index
  }
  return -1
}

export function remaining(checklist: Step[]): number {
  return checklist.filter(c => !c.done).length
}

/** "Il reste 3 points sur 8." */
export function remainingSentence(checklist: Step[]): string {
  const left = remaining(checklist)
  if (!checklist.length) return 'Pas de checklist pour ce ménage.'
  if (!left) return `Tous les points sont faits, ${checklist.length} sur ${checklist.length}. Dites « terminer » pour finir.`
  return `Il reste ${left} point${left > 1 ? 's' : ''} sur ${checklist.length}.`
}

/** Areas of the photo round: points flagged "photo", by area (else label), each once, in checklist order. */
export function photoAreas(checklist: Step[]): string[] {
  const seen = new Set<string>()
  const out: string[] = []
  for (const c of checklist) {
    if (!c.photo) continue
    const area = (c.area ?? c.label).trim()
    const key = normalize(area)
    if (!seen.has(key)) {
      seen.add(key)
      out.push(area)
    }
  }
  return out
}

export const LEVEL_WORDS: Record<StockLevel, string> = { ok: 'OK', low: 'bas', empty: 'vide' }

/** Sentence read back to confirm a command before applying it. */
export function confirmation(command: Command): string {
  switch (command.kind) {
    case 'check': return `Je coche « ${command.label} » ?`
    case 'stock': return command.quantity !== null && command.level === 'ok'
      ? `${command.name} : ${command.quantity} utilisé${command.quantity > 1 ? 's' : ''}, je l’enregistre ?`
      : `${command.name} : ${LEVEL_WORDS[command.level]}${command.quantity !== null ? `, ${command.quantity} utilisé${command.quantity > 1 ? 's' : ''}` : ''}. Je l’enregistre ?`
    case 'incident': return `Je signale : « ${command.text} » ?`
    default: return ''
  }
}

export const HELP = 'Dites « c’est fait » ou « suivant » pour valider, « précédent », « passer », « répète », « où j’en suis », '
  + '« combien il reste », « pause », « terminer ». Vous pouvez aussi dire « j’ai fini la salle de bain », « il manque du papier toilette » '
  + 'ou « signaler un problème : … ».'

// ——— Checklist template lines (one per line in the editor) ———

export interface TemplateLine { label: string, synonyms?: string[], photo?: boolean, area?: string }

/** "Salle de bain | sdb, douche | photo: Salle d'eau" → line. Second part: synonyms; third: "photo" (optionally ": area"). */
export function parseTemplateLine(raw: string): TemplateLine | null {
  const [label = '', synonyms = '', photo = ''] = raw.split('|').map(s => s.trim())
  if (!label) return null
  const line: TemplateLine = { label }
  const list = synonyms.split(',').map(s => s.trim()).filter(Boolean)
  if (list.length) line.synonyms = list
  const p = /^(?:photo|📷)\s*(?::\s*(.+))?$/i.exec(photo)
  if (p) {
    line.photo = true
    if (p[1]?.trim()) line.area = p[1].trim()
  }
  return line
}

export function formatTemplateLine(line: TemplateLine): string {
  const parts = [line.label, (line.synonyms ?? []).join(', ')]
  if (line.photo) parts.push(line.area ? `photo: ${line.area}` : 'photo')
  while (parts.length > 1 && !parts[parts.length - 1]) parts.pop()
  return parts.join(' | ')
}
