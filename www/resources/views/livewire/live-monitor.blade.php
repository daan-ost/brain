<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Live Monitor</h1>
        <p class="mt-1 text-sm text-gray-500">Real-time position, telemetry, events and camera feed.</p>
    </div>

    {{-- 4-panel grid: stacks on mobile, 2x2 on desktop --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {{-- Panel 1: Position Canvas --}}
        <div class="rounded-lg bg-white shadow-sm border border-gray-200 p-4"
             x-data="{
                canvasSize: Math.min(300, window.innerWidth - 64),
                _resizeHandler: null,
                init() {
                    this.updateCanvasSize();
                    this._resizeHandler = () => this.updateCanvasSize();
                    window.addEventListener('resize', this._resizeHandler);
                    this.$nextTick(() => this.drawGrid());
                },
                destroy() {
                    window.removeEventListener('resize', this._resizeHandler);
                },
                updateCanvasSize() {
                    this.canvasSize = Math.min(300, window.innerWidth - 64);
                    this.$nextTick(() => this.drawGrid());
                },
                drawGrid() {
                    const canvas = this.$refs.positionCanvas;
                    if (!canvas) return;
                    const ctx = canvas.getContext('2d');
                    const size = this.canvasSize;

                    ctx.clearRect(0, 0, size, size);

                    // Grid lines
                    ctx.strokeStyle = '#e5e7eb';
                    ctx.lineWidth = 1;
                    const step = size / 10;
                    for (let i = 0; i <= 10; i++) {
                        ctx.beginPath();
                        ctx.moveTo(i * step, 0);
                        ctx.lineTo(i * step, size);
                        ctx.stroke();
                        ctx.beginPath();
                        ctx.moveTo(0, i * step);
                        ctx.lineTo(size, i * step);
                        ctx.stroke();
                    }

                    // Center crosshair
                    ctx.strokeStyle = '#9ca3af';
                    ctx.lineWidth = 1;
                    ctx.setLineDash([4, 4]);
                    ctx.beginPath();
                    ctx.moveTo(size / 2, 0);
                    ctx.lineTo(size / 2, size);
                    ctx.stroke();
                    ctx.beginPath();
                    ctx.moveTo(0, size / 2);
                    ctx.lineTo(size, size / 2);
                    ctx.stroke();
                    ctx.setLineDash([]);

                    // Demo position dot
                    ctx.fillStyle = '#10b981';
                    ctx.beginPath();
                    ctx.arc(size * 0.6, size * 0.4, 6, 0, Math.PI * 2);
                    ctx.fill();

                    // Label
                    ctx.fillStyle = '#374151';
                    ctx.font = '12px Inter, sans-serif';
                    ctx.fillText('Position', 8, size - 8);
                }
             }">
            <h2 class="text-sm font-semibold text-gray-700 mb-3">Position</h2>
            <div class="flex justify-center">
                <canvas
                    x-ref="positionCanvas"
                    :width="canvasSize"
                    :height="canvasSize"
                    class="w-full max-w-[300px] mx-auto rounded border border-gray-100"
                ></canvas>
            </div>
        </div>

        {{-- Panel 2: Telemetry Gauges --}}
        <div class="rounded-lg bg-white shadow-sm border border-gray-200 p-4">
            <h2 class="text-sm font-semibold text-gray-700 mb-3">Telemetry</h2>
            <div class="grid grid-cols-2 gap-3 sm:gap-4">
                {{-- Speed --}}
                <div class="rounded-lg bg-gray-50 border border-gray-100 p-3 sm:p-4 text-center">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Speed</p>
                    <p class="text-2xl sm:text-3xl font-bold text-gray-900 mt-1">42</p>
                    <p class="text-xs text-gray-400 mt-1">km/h</p>
                </div>
                {{-- Altitude --}}
                <div class="rounded-lg bg-gray-50 border border-gray-100 p-3 sm:p-4 text-center">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Altitude</p>
                    <p class="text-2xl sm:text-3xl font-bold text-gray-900 mt-1">128</p>
                    <p class="text-xs text-gray-400 mt-1">m</p>
                </div>
                {{-- Battery --}}
                <div class="rounded-lg bg-gray-50 border border-gray-100 p-3 sm:p-4 text-center">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Battery</p>
                    <p class="text-2xl sm:text-3xl font-bold text-emerald-600 mt-1">87</p>
                    <p class="text-xs text-gray-400 mt-1">%</p>
                </div>
                {{-- Signal --}}
                <div class="rounded-lg bg-gray-50 border border-gray-100 p-3 sm:p-4 text-center">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Signal</p>
                    <p class="text-2xl sm:text-3xl font-bold text-gray-900 mt-1">-62</p>
                    <p class="text-xs text-gray-400 mt-1">dBm</p>
                </div>
            </div>
        </div>

        {{-- Panel 3: Event Log --}}
        <div class="rounded-lg bg-white shadow-sm border border-gray-200 p-4">
            <h2 class="text-sm font-semibold text-gray-700 mb-3">Event Log</h2>
            <div class="overflow-y-auto max-h-[250px] sm:max-h-[380px] space-y-2">
                @foreach($events as $event)
                    <div class="flex items-start gap-2 text-sm py-1.5 px-2 rounded {{ $event['type'] === 'error' ? 'bg-red-50' : ($event['type'] === 'warning' ? 'bg-yellow-50' : 'bg-gray-50') }}">
                        <span class="text-xs text-gray-400 font-mono whitespace-nowrap mt-0.5">{{ $event['time'] }}</span>
                        <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-xs font-medium
                            {{ $event['type'] === 'error' ? 'bg-red-100 text-red-700' : ($event['type'] === 'warning' ? 'bg-yellow-100 text-yellow-700' : 'bg-blue-100 text-blue-700') }}
                        ">{{ $event['type'] }}</span>
                        <span class="text-gray-700">{{ $event['message'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Panel 4: Camera Feed --}}
        <div class="rounded-lg bg-white shadow-sm border border-gray-200 p-4">
            <h2 class="text-sm font-semibold text-gray-700 mb-3">Camera Feed</h2>
            <div class="w-full rounded border border-gray-100 bg-gray-900 flex items-center justify-center" style="aspect-ratio: 640/360;">
                <div class="text-center text-gray-500">
                    <svg class="mx-auto h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                    <p class="mt-2 text-sm">No feed available</p>
                </div>
            </div>
        </div>

    </div>
</div>
