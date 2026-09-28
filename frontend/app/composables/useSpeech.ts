/**
 * Speech for the cleaning assistant, in French, with the browser only (Web Speech API), as in Doc Assist
 * (frontend/app/composables/useSpeech.ts there): recognition (SpeechRecognition / webkitSpeechRecognition) and read-back
 * (speechSynthesis). No audio ever reaches Rocket Clean's server.
 * - push-to-talk: `listenOnce()` hears one sentence; hands-free: `start()` listens continuously until `stop()`;
 * - what the phone says itself is not heard as a command: results are ignored while it speaks;
 * - `supported` false (Firefox, some in-app browsers) or `error` set (microphone refused): the page keeps its buttons.
 *
 * Privacy: Chrome (Android, desktop) sends the audio to Google's servers for transcription; Safari (iOS 17+, macOS)
 * transcribes on the device when possible. Nothing is recorded or kept by Rocket Clean, only the recognised text is
 * handled on the phone.
 */
interface RecognitionResult { isFinal: boolean, 0: { transcript: string } }
interface RecognitionEvent { resultIndex: number, results: ArrayLike<RecognitionResult> }
interface Recognition {
  lang: string
  continuous: boolean
  interimResults: boolean
  start: () => void
  stop: () => void
  abort: () => void
  onresult: ((event: RecognitionEvent) => void) | null
  onend: (() => void) | null
  onerror: ((event: { error: string }) => void) | null
}

const LANG = 'fr-FR'

export function useSpeech(onSentence: (sentence: string) => void) {
  const supported = ref(false)
  const canSpeak = ref(false)
  const listening = ref(false)
  const continuous = ref(false)
  const speaking = ref(false)
  const interim = ref('')
  const error = ref<string | null>(null)
  let recognition: Recognition | null = null
  let wanted = false
  let voice: SpeechSynthesisVoice | null = null

  function pickVoice() {
    voice = speechSynthesis.getVoices().find(v => v.lang === LANG) ?? speechSynthesis.getVoices().find(v => v.lang.startsWith('fr')) ?? null
  }

  onMounted(() => {
    canSpeak.value = typeof speechSynthesis !== 'undefined'
    if (canSpeak.value) {
      pickVoice()
      speechSynthesis.addEventListener?.('voiceschanged', pickVoice)
    }
    const w = window as unknown as { SpeechRecognition?: new () => Recognition, webkitSpeechRecognition?: new () => Recognition }
    const Ctor = w.SpeechRecognition ?? w.webkitSpeechRecognition
    supported.value = !!Ctor
    if (!Ctor) return
    recognition = new Ctor()
    recognition.lang = LANG
    recognition.interimResults = true
    recognition.onresult = (event) => {
      let partial = ''
      for (let i = event.resultIndex; i < event.results.length; i++) {
        const result = event.results[i]!
        const text = result[0].transcript.trim()
        if (speaking.value) continue
        if (result.isFinal) {
          if (text) onSentence(text)
        }
        else {
          partial += text + ' '
        }
      }
      interim.value = partial.trim()
    }
    recognition.onerror = (event) => {
      if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
        error.value = 'Micro refusé : autorisez le micro pour ce site, ou utilisez les boutons.'
        wanted = false
      }
      else if (event.error === 'network') {
        error.value = 'Reconnaissance vocale indisponible sans réseau sur ce navigateur : utilisez les boutons.'
        wanted = false
      }
    }
    recognition.onend = () => {
      interim.value = ''
      if (wanted && continuous.value) {
        try {
          recognition?.start()
          return
        }
        catch { /* restarted too fast: stop */ }
      }
      wanted = false
      listening.value = false
    }
  })

  function begin(keepGoing: boolean) {
    if (!recognition) return
    error.value = null
    continuous.value = keepGoing
    recognition.continuous = keepGoing
    wanted = true
    try {
      recognition.start()
    }
    catch { /* already started */ }
    listening.value = true
  }

  /** Hands-free: listens until stop(). */
  const start = () => begin(true)
  /** Push-to-talk: one sentence. */
  const listenOnce = () => begin(false)

  function stop() {
    wanted = false
    continuous.value = false
    recognition?.abort()
    listening.value = false
  }

  /** Reads a sentence aloud (queued after the current one unless $interrupt). Resolves when finished. */
  function speak(text: string, interrupt = true): Promise<void> {
    if (!text || !canSpeak.value) return Promise.resolve()
    if (interrupt) speechSynthesis.cancel()
    return new Promise((resolve) => {
      const utterance = new SpeechSynthesisUtterance(text)
      utterance.lang = LANG
      if (voice) utterance.voice = voice
      utterance.rate = 1.05
      speaking.value = true
      const done = () => {
        // Let the echo fade before listening to the cleaner again.
        setTimeout(() => {
          speaking.value = speechSynthesis.speaking
          resolve()
        }, 250)
      }
      utterance.onend = done
      utterance.onerror = done
      speechSynthesis.speak(utterance)
    })
  }

  function silence() {
    if (canSpeak.value) speechSynthesis.cancel()
    speaking.value = false
  }

  onBeforeUnmount(() => {
    stop()
    silence()
    if (canSpeak.value) speechSynthesis.removeEventListener?.('voiceschanged', pickVoice)
  })

  return { supported, canSpeak, listening, continuous, speaking, interim, error, start, listenOnce, stop, speak, silence }
}
