<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de KPI — EcoRuta Temuco</title>
    <style>
        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10.5px;
            color: #1f2933;
            margin: 18px;
        }
        h1 {
            font-size: 17px;
            color: #1b5e20;
            margin: 0 0 4px 0;
        }
        .sub {
            color: #52606d;
            margin-bottom: 14px;
            font-size: 10px;
        }
        h2 {
            font-size: 12.5px;
            color: #1b5e20;
            border-bottom: 1.5px solid #2e7d32;
            padding-bottom: 3px;
            margin: 14px 0 8px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        th, td {
            border: 1px solid #d9e2ec;
            padding: 5px 6px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background-color: #edf7ed;
            color: #1b5e20;
            font-weight: bold;
        }
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: bold;
        }
        .cumple { background: #e8f5e9; color: #1b5e20; }
        .cerca { background: #fff3e0; color: #8c4a00; }
        .no_cumple { background: #ffebee; color: #b71c1c; }
        .sin_datos { background: #f1f5f9; color: #475569; }
        .nota {
            font-size: 9.5px;
            color: #52606d;
            margin-top: 8px;
        }
    </style>
</head>
<body>
    <h1>EcoRuta Temuco · Reporte Ejecutivo de KPI</h1>
    <div class="sub">
        Período: <strong>{{ $dashboard['periodo']['fecha_inicio'] }}</strong> al
        <strong>{{ $dashboard['periodo']['fecha_fin'] }}</strong>
        · Agrupación: {{ $dashboard['periodo']['agrupacion'] }}
        · Generado: {{ $generadoEn }}
    </div>

    @foreach ($dashboard['categorias'] as $cat)
        <h2>{{ $cat['titulo'] }}</h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 8%;">KPI</th>
                    <th style="width: 24%;">Indicador</th>
                    <th style="width: 15%;">Valor</th>
                    <th style="width: 15%;">Meta</th>
                    <th style="width: 12%;">Estado</th>
                    <th style="width: 26%;">Interpretación</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($dashboard['kpis'] as $kpi)
                    @if ($kpi['categoria'] === $cat['id'])
                        @php
                            $etiqueta = match ($kpi['semaforo']) {
                                'cumple' => 'Cumple',
                                'cerca' => 'Cerca',
                                'no_cumple' => 'No cumple',
                                default => 'Sin datos',
                            };
                        @endphp
                        <tr>
                            <td><strong>{{ $kpi['id'] }}</strong></td>
                            <td>
                                {{ $kpi['titulo'] }}
                                @if (!empty($kpi['es_proyeccion']))
                                    <br><span class="nota">(Proyección)</span>
                                @endif
                            </td>
                            <td>
                                @if ($kpi['sin_datos_suficientes'])
                                    <span class="nota">{{ $kpi['mensaje_sin_datos'] }}</span>
                                @else
                                    <strong>{{ $kpi['valor_formateado'] }}</strong>
                                @endif
                            </td>
                            <td>{{ $kpi['meta'] }}</td>
                            <td><span class="badge {{ $kpi['semaforo'] }}">{{ $etiqueta }}</span></td>
                            <td>{{ $kpi['interpretacion'] }}</td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    @endforeach

    <h2>Parámetros operativos utilizados</h2>
    <table>
        <thead>
            <tr>
                <th>Parámetro</th>
                <th>Valor</th>
                <th>Unidad</th>
                <th>Origen</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($dashboard['parametros'] as $p)
                <tr>
                    <td>{{ $p['etiqueta'] }}</td>
                    <td>{{ number_format((float) $p['valor'], 2, ',', '.') }}</td>
                    <td>{{ $p['unidad'] }}</td>
                    <td>{{ $p['es_referencia'] ? 'Valor de referencia' : 'Personalizado por empresa' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
