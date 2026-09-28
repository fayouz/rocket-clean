// Unit tests of the voice assistant's parser: `npm run test` (node --test, Node 22.18+/24 strips the types itself).
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { confirmation, formatTemplateLine, match, nextOpen, normalize, parseCommand, parseTemplateLine, photoAreas, quantity, remainingSentence, stems } from '../app/utils/assistant/parser.ts'
import type { StockItem } from '../app/utils/assistant/parser.ts'

const checklist = [
  { label: 'Salle de bain', synonyms: ['sdb', 'douche'] },
  { label: 'Faire les lits' },
  { label: 'Vider les poubelles' },
  { label: 'Nettoyer la cuisine', synonyms: ['plan de travail'] },
  { label: 'Aspirateur salon' },
]
const stock: StockItem[] = [
  { id: 's1', name: 'Papier toilette', level: 'ok' },
  { id: 's2', name: 'Café', level: 'ok' },
  { id: 's3', name: 'Liquide vaisselle', level: 'ok' },
  { id: 's4', name: 'Sacs poubelle', level: 'low' },
]
const parse = (s: string) => parseCommand(s, checklist, stock)

test('normalize and stems', () => {
  assert.equal(normalize('Salle de Bain, NETTOYÉE !'), 'salle de bain nettoyee')
  assert.equal(normalize('Œuf'), 'oeuf')
  assert.deepEqual(stems('les rouleaux de papier'), ['roulea', 'papier'])
})

test('explicit commands, whole sentence only', () => {
  for (const [said, kind] of [
    ['Suivant', 'next'], ['C’est fait', 'next'], ['valider', 'next'], ['Précédent', 'previous'], ['répète', 'repeat'],
    ['Pause', 'pause'], ['on reprend', 'resume'], ['Où j’en suis ?', 'where'], ['Combien il reste', 'remaining'],
    ['il reste combien de points', 'remaining'], ['Terminer', 'finish'], ['j’ai terminé', 'finish'], ['passer', 'skip'],
    ['oui', 'yes'], ['non', 'no'], ['Assistant, suivant', 'next'], ['suivant s’il te plaît', 'next'], ['aide', 'help'],
  ] as const) {
    assert.equal(parse(said).kind, kind, said)
  }
})

test('free speech ticks the right point', () => {
  const cases: [string, number][] = [
    ['j’ai fini la salle de bain', 0], ['J\'ai fini la SDB', 0], ['la douche est faite', 0], ['les lits sont faits', 1],
    ['j’ai vidé les poubelles', 2], ['cuisine nettoyée', 3], ['plan de travail fait', 3], ['salle de bain', 0],
  ]
  for (const [said, index] of cases) {
    const c = parse(said)
    assert.equal(c.kind, 'check', said)
    assert.equal(c.kind === 'check' && c.index, index, said)
  }
  // "J'ai terminé la salle de bain" is a point, not the end of the cleaning.
  assert.equal(parse('j’ai terminé la salle de bain').kind, 'check')
  assert.equal(parse('il fait beau aujourd’hui').kind, 'unknown')
})

test('stock by voice', () => {
  const c1 = parse('il manque du papier toilette')
  assert.deepEqual(c1, { kind: 'stock', id: 's1', name: 'Papier toilette', level: 'empty', quantity: null })
  assert.deepEqual(parse('plus de café'), { kind: 'stock', id: 's2', name: 'Café', level: 'empty', quantity: null })
  assert.deepEqual(parse('Le liquide vaisselle est bas'), { kind: 'stock', id: 's3', name: 'Liquide vaisselle', level: 'low', quantity: null })
  assert.deepEqual(parse('j’ai utilisé deux rouleaux'), { kind: 'stock', id: 's1', name: 'Papier toilette', level: 'ok', quantity: 2 })
  assert.deepEqual(parse('il n’y a plus de PQ'), { kind: 'stock', id: 's1', name: 'Papier toilette', level: 'empty', quantity: null })
  assert.deepEqual(parse('j’ai pris 3 capsules'), { kind: 'stock', id: 's2', name: 'Café', level: 'ok', quantity: 3 })
  assert.equal(parse('le café est presque vide').kind === 'stock' && (parse('le café est presque vide') as { level: string }).level, 'low')
  // Unknown item: not a stock command.
  assert.equal(parse('il manque du champagne').kind, 'unknown')
})

test('incidents keep the words as said', () => {
  assert.deepEqual(parse('Signaler un problème : la poignée de la douche est cassée'), { kind: 'incident', text: 'la poignée de la douche est cassée' })
  assert.deepEqual(parse('problème avec la chasse d’eau qui fuit'), { kind: 'incident', text: 'la chasse d’eau qui fuit' })
})

test('match scores and quantity', () => {
  assert.equal(match('salle de bain', checklist)[0]?.index, 0)
  assert.deepEqual(quantity('deux rouleaux'), [2, 'rouleaux'])
  assert.deepEqual(quantity('une eponge'), [null, 'une eponge'])
  assert.deepEqual(quantity('une eponge', true), [1, 'eponge'])
})

test('step tracking', () => {
  const steps = [{ label: 'a', done: true }, { label: 'b', done: false }, { label: 'c', done: false, photo: true, area: 'Chambre' }, { label: 'd', done: true, photo: true }]
  assert.equal(nextOpen(steps, 0), 1)
  assert.equal(nextOpen(steps, 3), 1)
  assert.equal(nextOpen(steps.map(s => ({ ...s, done: true })), 0), -1)
  assert.equal(remainingSentence(steps), 'Il reste 2 points sur 4.')
  assert.deepEqual(photoAreas([...steps, { label: 'e', done: false, photo: true, area: 'chambre' }]), ['Chambre', 'd'])
  assert.equal(confirmation({ kind: 'check', index: 0, label: 'Salle de bain', score: 1 }), 'Je coche « Salle de bain » ?')
})

test('template lines', () => {
  assert.deepEqual(parseTemplateLine('Salle de bain | sdb, douche | photo'), { label: 'Salle de bain', synonyms: ['sdb', 'douche'], photo: true })
  assert.deepEqual(parseTemplateLine('Faire les lits | | photo: Chambre'), { label: 'Faire les lits', photo: true, area: 'Chambre' })
  assert.deepEqual(parseTemplateLine('Poubelles'), { label: 'Poubelles' })
  assert.equal(parseTemplateLine('  '), null)
  for (const line of ['Salle de bain | sdb, douche | photo', 'Faire les lits |  | photo: Chambre', 'Poubelles', 'Cuisine | plan de travail']) {
    assert.equal(formatTemplateLine(parseTemplateLine(line)!), line)
  }
})
