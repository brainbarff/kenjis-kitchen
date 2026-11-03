// Short synthesized alerts avoid external audio files and work across modern browsers.
(() => {
    let audioContext;

    function playTone(frequency, start, duration, volume = 0.16) {
        if (!audioContext) return;
        const oscillator = audioContext.createOscillator();
        const gain = audioContext.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.value = frequency;
        gain.gain.setValueAtTime(volume, audioContext.currentTime + start);
        gain.gain.exponentialRampToValueAtTime(0.001, audioContext.currentTime + start + duration);
        oscillator.connect(gain);
        gain.connect(audioContext.destination);
        oscillator.start(audioContext.currentTime + start);
        oscillator.stop(audioContext.currentTime + start + duration);
    }

    function play(kind) {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) return;
        try {
            audioContext ||= new AudioContextClass();
            if (audioContext.state === 'suspended') audioContext.resume().catch(() => {});
            // Incoming online orders use a bright double chime; Ready uses a distinct rising chime.
            const notes = kind === 'ready' ? [660, 880, 1046] : kind === 'serving' ? [523, 392] : [880, 660];
            notes.forEach((frequency, index) => playTone(frequency, index * (kind === 'ready' ? 0.16 : 0.2), 0.14));
        } catch (error) {
            // Audio is optional; blocked autoplay must not interrupt order tracking.
        }
    }

    window.KenjiOrderSounds = { play };
    const unlock = () => {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) return;
        try {
            audioContext ||= new AudioContextClass();
            if (audioContext.state === 'suspended') audioContext.resume().catch(() => {});
        } catch (error) {}
    };
    document.addEventListener('pointerdown', unlock, { once: true });
    document.addEventListener('keydown', unlock, { once: true });
})();
