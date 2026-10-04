$ErrorActionPreference = 'Stop'
$estado = Get-Content (Join-Path $PSScriptRoot 'manual/animales-http.json') -Raw | ConvertFrom-Json
$handler = [System.Net.Http.HttpClientHandler]::new()
$handler.AllowAutoRedirect = $false
$handler.UseCookies = $false
$cliente = [System.Net.Http.HttpClient]::new($handler)
function Peticion($ruta, $rol = '', $campos = $null) {
 $metodo = if ($null -eq $campos) { [System.Net.Http.HttpMethod]::Get } else { [System.Net.Http.HttpMethod]::Post }
 $req = [System.Net.Http.HttpRequestMessage]::new($metodo, ('http://127.0.0.1:8080' + $ruta))
 if ($rol) { $req.Headers.Add('Cookie', ('ECOFAUNA_SESION=' + $estado.sesiones.$rol)) }
 if ($null -ne $campos) {
  $dict = [System.Collections.Generic.Dictionary[string,string]]::new()
  foreach ($clave in $campos.Keys) { $dict.Add($clave,[string]$campos[$clave]) }
  $req.Content = [System.Net.Http.FormUrlEncodedContent]::new($dict)
 }
 $res = $cliente.SendAsync($req).GetAwaiter().GetResult()
 $texto = $res.Content.ReadAsStringAsync().GetAwaiter().GetResult()
 if ($texto -match 'Fatal error:|Warning:') { throw "Error PHP en $ruta" }
 return @{ Codigo=[int]$res.StatusCode; Texto=$texto; Tipo=$res.Content.Headers.ContentType.MediaType }
}
function Comprobar($condicion,$mensaje) { if (!$condicion) { throw $mensaje }; Write-Output "OK: $mensaje" }
$r = Peticion '/administracion/animales.php'
Comprobar ($r.Codigo -eq 302) 'Acceso anónimo bloqueado'
$r = Peticion '/administracion/animales.php' 'Cliente'
Comprobar ($r.Codigo -eq 302) 'Cliente sin acceso a administración'
$r = Peticion '/animales.php' 'Cliente'
Comprobar ($r.Codigo -eq 200 -and ([regex]::Matches($r.Texto,'class="animal-tarjeta"').Count -eq 12)) 'Catálogo con 12 tarjetas en la primera página'
$r = Peticion '/animales.php?pagina=2' 'Cliente'
Comprobar ($r.Codigo -eq 200 -and ([regex]::Matches($r.Texto,'class="animal-tarjeta"').Count -eq 1)) 'Segunda página del catálogo'
$r = Peticion '/animales.php?buscar=inexistente' 'Cliente'
Comprobar ($r.Texto.Contains('No encontramos animales')) 'Estado vacío del catálogo'
$r = Peticion '/administracion/animales.php?nuevo=1' 'Administrador'
$token = [regex]::Matches($r.Texto,'name="csrf_token" value="([a-f0-9]+)"') | ForEach-Object { $_.Groups[1].Value } | Select-Object -Last 1
Comprobar ($r.Codigo -eq 200 -and $token.Length -eq 64) 'Formulario de alta con token CSRF'
$campos = @{accion='guardar';id_animal='0';codigo_animal='HTTP-001';nombre_animal='Animal creado por HTTP';fecha_nacimiento='2020-01-01';fecha_entrada='2021-01-01';peso='12.5';altura='1.2';id_habitat='';csrf_token='incorrecto'}
$r = Peticion '/administracion/animales.php?nuevo=1' 'Administrador' $campos
Comprobar ($r.Codigo -eq 403) 'Token inválido rechazado'
$campos.csrf_token = $token
$r = Peticion '/administracion/animales.php?nuevo=1' 'Cliente' $campos
Comprobar ($r.Codigo -eq 302) 'Cliente sin permiso para crear'
$r = Peticion '/administracion/animales.php?nuevo=1' 'Administrador' $campos
Comprobar ($r.Codigo -eq 303) 'Alta válida y redirección después del POST'
$r = Peticion '/animales.php?buscar=HTTP-001' 'Cliente'
Comprobar ($r.Texto.Contains('Animal creado por HTTP') -and [regex]::Matches($r.Texto,'class="animal-tarjeta"').Count -eq 1) 'Animal nuevo visible al cliente'
$r = Peticion '/administracion/animales.php' 'Administrador'
$fotoId = [regex]::Match($r.Texto,'animales/foto.php\?id=(\d+)').Groups[1].Value
$r = Peticion ('/animales/foto.php?id=' + $fotoId) 'Cliente'
Comprobar ($r.Codigo -eq 200 -and $r.Tipo -eq 'image/png') 'Fotografía servida con su tipo correcto'
$r = Peticion ('/animales/foto.php?id=' + $fotoId)
Comprobar ($r.Codigo -eq 403) 'Fotografía protegida sin sesión'
$r = Peticion '/css/animales.css'
Comprobar ($r.Codigo -eq 200) 'Estilos del módulo disponibles'
$cliente.Dispose()
