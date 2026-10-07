from http.server import BaseHTTPRequestHandler, HTTPServer
import json

class Manejador(BaseHTTPRequestHandler):
    # Oculta los mensajes de log por defecto para no ensuciar la pantalla
    def log_message(self, format, *args):
        pass

    def do_POST(self):
        # Leer el tamaño y luego el contenido del mensaje
        longitud = int(self.headers['Content-Length'])
        datos = self.rfile.read(longitud)
        
        print("\n--- JSON Recibido desde el ESP32 ---")
        try:
            # Formatear el JSON para que se vea ordenado en tu captura
            json_obj = json.loads(datos.decode('utf-8'))
            print(json.dumps(json_obj, indent=4))
        except:
            print(datos.decode('utf-8'))
            
        # Responder con HTTP 200 OK (Lo que necesita tu ESP32 para calcular la latencia)
        self.send_response(200)
        self.end_headers()

# Levantar el servidor en el puerto 8080 para todas las interfaces de red (0.0.0.0)
servidor = HTTPServer(('0.0.0.0', 8080), Manejador)
print("Servidor HTTP local corriendo en el puerto 8080...")
print("Esperando datos del ESP32...")
servidor.serve_forever()