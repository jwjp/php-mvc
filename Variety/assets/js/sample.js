// 특정 함수와 변수를 가져오기
import multiply, { add, subtract, PI } from './sample-module.js';
import moment from '/modules/moment/dist/moment.js'
import mqtt from '/modules/mqtt/dist/mqtt.esm.js'

console.log(add(5, 3)); // 8
console.log(subtract(10, 4)); // 6
console.log(PI); // 3.141592
console.log(moment().format('YYYY-MM-DD'));

// MQTT 예시
const options = {
    username: 'your-username', // MQTT 브로커 사용자 이름
    password: 'your-password', // MQTT 브로커 비밀번호
    clientId: 'Client_' + Math.random().toString(16).substring(2, 8), // 고유 클라이언트 ID
    reconnectPeriod: 1000 // 재연결 간격 (ms)
};

// MQTT 실행하려면 MQTT broker 설치 및 실행 필요
// 무료 브로커 (https://mosquitto.org/) 설치 후 mosquitto.conf 수정
// listener 1883
// protocol mqtt
// listener 8081
// protocol websockets
// allow_anonymous true
const client = mqtt.connect("ws://localhost:8081", options);

client.subscribe('test/topic', { qos: 2 });
client.publish('test/topic', 'Hello MQTT!', { qos: 2 });

client.on('message', (topic, message) => {
    console.log(message.toString());
});

client.on("connect", () => {
    console.log("connect");
}).on('disconnect', () => {
    console.log('disconnect');
}).on('reconnect', () => {
    console.log('reconnect...');
}).on('error', (err) => {
    console.error('error: ', err);
    // client.end();
})
