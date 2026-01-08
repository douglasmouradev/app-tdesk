#!/bin/bash

# Script para iniciar o servidor TDesk Solutions
# Para processos anteriores e inicia novo servidor

PORT=8080

echo "🛑 Parando processos anteriores na porta $PORT..."
pkill -f "php -S localhost:$PORT" 2>/dev/null
sleep 1

if lsof -ti:$PORT >/dev/null 2>&1; then
    echo "⚠️  Ainda há processo na porta $PORT. Forçando parada..."
    kill -9 $(lsof -ti:$PORT) 2>/dev/null
    sleep 1
fi

if lsof -ti:$PORT >/dev/null 2>&1; then
    echo "❌ Não foi possível liberar a porta $PORT"
    echo "   Tente usar outra porta ou feche o processo manualmente"
    exit 1
fi

echo "✅ Porta $PORT liberada"
echo ""
echo "🚀 Iniciando servidor em http://localhost:$PORT"
echo "📌 Pressione Ctrl+C para parar"
echo ""

php -S localhost:$PORT -t public

