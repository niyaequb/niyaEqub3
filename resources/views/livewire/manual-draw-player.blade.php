<div class="manual-draw-global-container" 
     x-data="{ 
        isOpen: @entangle('isOpen'),
        state: @entangle('state'),
        winnerId: @entangle('winnerId'),
        winners: @entangle('winners'),
        drawExecuted: @entangle('drawExecuted'),
        timerDelay: @entangle('timerDelay'),
        equbGroupId: @entangle('equbGroupId'),
        timer: 0,
        interval: null,
        resetTimer() {
            this.timer = this.timerDelay;
            if (this.interval) clearInterval(this.interval);
            this.interval = setInterval(() => {
                if (this.timer > 0) {
                    this.timer--;
                } else {
                    clearInterval(this.interval);
                    if (this.drawExecuted) {
                        $wire.showResult();
                    }
                }
            }, 1000);
        }
     }"
     x-init="
        $watch('isOpen', value => {
            if (value && state === 'spinning') {
                resetTimer();
                $wire.startDraw();
            }
        });
        $watch('drawExecuted', value => {
            if (value && timer <= 0) $wire.showResult();
        });
     "
     @start-manual-draw.window="isOpen = true; equbGroupId = $event.detail.equbGroupId; state = 'spinning'; winnerId = null; drawExecuted = false; timer = timerDelay;"
     @draw-completed.window="drawExecuted = true; if (timer <= 0) $wire.showResult();">
    
    <template x-teleport="body">
        <!-- Modal Backdrop -->
        <div x-show="isOpen"
             class="fixed inset-0 z-[99999999] flex items-center justify-center p-4 bg-gray-900/60 backdrop-blur-sm transition-all duration-300"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0">
            
            <!-- State: Spinning (Finding Winner Card) -->
            <div x-show="state === 'spinning' && isOpen"
                 class="relative max-w-[380px] w-full bg-white dark:bg-gray-900 rounded-[3rem] p-10 shadow-[0_40px_80px_rgba(0,0,0,0.4)] border border-gray-100 dark:border-gray-800 text-center animate-in zoom-in-95 duration-300">
                
                <div class="flex flex-col items-center space-y-8">
                    <div class="relative flex items-center justify-center">
                        <div class="absolute w-32 h-32 bg-primary-500/10 blur-[60px] rounded-full animate-pulse"></div>
                        <div class="w-20 h-20 rounded-full border-[3px] border-primary-500/10 border-t-primary-500 animate-[spin_0.8s_linear_infinite]"></div>
                        <div class="absolute inset-0 flex items-center justify-center">
                             <span class="text-4xl animate-bounce">🎰</span>
                        </div>
                    </div>
                    
                    <div class="text-center space-y-4">
                        <h2 class="text-gray-900 dark:text-white text-2xl font-black tracking-[0.4em] uppercase italic opacity-90 animate-pulse" x-text="winners.length > 1 ? 'Finding Winners' : 'Finding Winner'">Finding Winner</h2>
                        <p class="text-primary-600 text-[0.6rem] font-black tracking-[0.3em] uppercase italic" x-text="winners.length > 1 ? 'Calculating ' + winners.length + ' Winners...' : 'Calculating...'"></p>
                        
                        <div class="mt-6 text-primary-600 dark:text-white text-3xl font-mono tracking-widest bg-primary-50 dark:bg-white/5 py-3 px-8 rounded-full border border-primary-100 dark:border-white/10">
                            <span x-text="timer"></span>s
                        </div>
                    </div>
                </div>
            </div>

            <!-- State: Result (Celebration Card) -->
            <div x-show="state === 'result' && isOpen"
                 class="fixed inset-0 flex items-center justify-center p-4 overflow-hidden"
                 x-data="{
                    flowers: [],
                    init() {
                        this.flowers = Array.from({ length: 150 }, () => ({
                            id: Math.random(),
                            x: Math.random() * 100,
                            delay: Math.random() * 4,
                            duration: 5 + Math.random() * 6,
                            size: 0.8 + Math.random() * 1.5,
                            emoji: ['⭐', '✨', '🏆', '👑', '🎉', '🥇', '💰'][Math.floor(Math.random() * 7)]
                        }));
                    }
                 }">
                
                <!-- Celebration Flowers Layer -->
                <div class="absolute inset-0 pointer-events-none overflow-hidden z-[10]">
                    <template x-for="f in flowers" :key="f.id">
                        <div class="absolute top-[-10%] animate-fall-dense"
                             :style="`left: ${f.x}%; animation-delay: ${f.delay}s; animation-duration: ${f.duration}s; font-size: ${f.size}rem;`"
                             x-text="f.emoji"></div>
                    </template>
                </div>

                <!-- Compact Winner Card -->
                <div x-transition:enter="transition-all cubic-bezier(0.175, 0.885, 0.32, 1.275) duration-700"
                     x-transition:enter-start="opacity-0 scale-50 translate-y-32"
                     x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                     class="relative z-[20] max-w-[340px] w-full bg-white dark:bg-gray-900 rounded-[4rem] p-10 shadow-[0_50px_100px_rgba(0,0,0,0.8),0_0_80px_rgba(245,158,11,0.3)] border border-amber-500/20 overflow-hidden text-center mx-4">
                    
                    <div class="relative z-10">
                        <div class="mb-8 relative inline-flex">
                            <div class="absolute inset-x-0 bottom-[-25%] h-1/2 bg-amber-500/30 blur-xl rounded-full"></div>
                            <div class="relative bg-gradient-to-br from-amber-300 via-amber-500 to-amber-700 w-16 h-16 rounded-[1.8rem] flex items-center justify-center shadow-lg">
                                 <span class="text-4xl">👑</span>
                            </div>
                        </div>

                        <h1 class="text-gray-950 dark:text-white text-3xl font-black italic tracking-tight mb-1 uppercase leading-none">CONGRATS!</h1>
                        <p class="text-amber-600 dark:text-amber-500 text-[0.6rem] font-black uppercase tracking-[0.4em] mb-8 italic opacity-80" x-text="winners.length > 1 ? 'Official Draw Winners' : 'Official Draw Winner'"></p>

                        <div class="relative mb-10">
                            <div class="relative py-8 px-4 bg-gray-50/80 dark:bg-gray-800/90 rounded-[3rem] border border-amber-500/10 shadow-inner overflow-y-auto max-h-[200px]">
                                <template x-if="winners.length <= 1">
                                    <div>
                                        <span class="block text-[0.5rem] text-gray-500 dark:text-gray-400 font-bold uppercase tracking-[0.3em] mb-4">Winner ID</span>
                                        <span class="text-5xl font-black text-transparent bg-clip-text bg-gradient-to-b from-amber-400 to-amber-700 tracking-tighter" x-text="'#' + (winnerId || '???')"></span>
                                    </div>
                                </template>
                                <template x-if="winners.length > 1">
                                    <div class="space-y-4">
                                        <span class="block text-[0.5rem] text-gray-500 dark:text-gray-400 font-bold uppercase tracking-[0.3em]">Winners</span>
                                        <div class="flex flex-col space-y-2">
                                            <template x-for="name in winners" :key="name">
                                                <span class="text-lg font-black text-amber-700 dark:text-amber-400" x-text="name"></span>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <button wire:click="resetDraw"
                                class="group relative w-full h-[64px] rounded-full bg-gray-950 dark:bg-white overflow-hidden shadow-xl transition-all hover:scale-105">
                            <div class="absolute inset-0 bg-gradient-to-r from-amber-600 via-amber-500 to-amber-600 opacity-0 group-hover:opacity-100 transition-opacity"></div>
                            <span class="relative text-white dark:text-gray-950 group-hover:text-white text-xs font-black uppercase tracking-[0.4em]">Perfect!</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <style>
    @keyframes fall-dense {
        0% { transform: translateY(-50px) rotate(0deg) scale(0); opacity: 0; }
        15% { opacity: 1; scale(1); }
        85% { opacity: 1; scale(1); }
        100% { transform: translateY(110vh) rotate(540deg) scale(0.5); opacity: 0; }
    }
    .animate-fall-dense {
        animation: fall-dense linear forwards;
    }
    </style>
</div>
