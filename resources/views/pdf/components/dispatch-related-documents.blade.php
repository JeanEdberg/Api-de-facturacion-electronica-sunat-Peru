{{-- PDF Dispatch Related Documents Component --}}
{{-- Props: $documentos_relacionados, $format --}}
{{-- Se muestra cuando el traslado se sustenta en otro comprobante (por compra). --}}

@php($docs = array_filter((array) ($documentos_relacionados ?? [])))

@if(count($docs) > 0)
    @if(in_array($format, ['a4', 'A4', 'a5', 'A5']))
        <div class="observations-section">
            <div class="observations-header">
                <h4>DOCUMENTOS RELACIONADOS</h4>
            </div>
            <div class="observations-content">
                @foreach($docs as $doc)
                    <p>{{ $doc['tipo_desc'] ?? 'DOCUMENTO' }}: {{ $doc['numero'] ?? '' }}@if(!empty($doc['fecha'])) ({{ $doc['fecha'] }})@endif</p>
                @endforeach
            </div>
        </div>
    @else
        <div class="reference-doc">
            <div class="section-title">DOCUMENTOS RELACIONADOS:</div>
            @foreach($docs as $doc)
                <div>{{ $doc['tipo_desc'] ?? 'DOCUMENTO' }}: {{ $doc['numero'] ?? '' }}@if(!empty($doc['fecha'])) ({{ $doc['fecha'] }})@endif</div>
            @endforeach
        </div>
    @endif
@endif
