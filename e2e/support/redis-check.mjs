// Stops the browser tests before they start, saying what to do, when Redis is not running.
// Without it, the Workbench app's build fails with a connection error instead.
import net from 'node:net';

const host = process.env.REDIS_HOST ?? '127.0.0.1';
const port = Number(process.env.REDIS_PORT ?? '6379');

const socket = net.connect({ host, port, timeout: 2000 });

const fail = () => {
    console.error(`The browser tests need Redis at ${host}:${port}. Start it with: docker compose up -d redis`);
    process.exit(1);
};

socket.on('connect', () => {
    socket.end();
    process.exit(0);
});
socket.on('error', fail);
socket.on('timeout', fail);
