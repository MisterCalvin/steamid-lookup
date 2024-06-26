<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SteamID Lookup</title>
<link rel="stylesheet" href="/css/styles.css">
</head>
<body>
<div class="container">
  <div class="input-group">
    <label for="steamID"></label>
    <input type="text" id="steamID" name="steamID" placeholder="STEAM_0:0:1234...">
    <button id="lookupButton">lookup</button>
  </div>
  <div class="input-interpreted" id="inputInterpreted">
  </div>
  <div class="results" id="results">
  </div>
  <br>
  <div align="center">
    <a href="#" class="theme-toggle-button" id="theme-toggle">
      <img src="/images/dark.svg" alt="Toggle Theme" title="Toggle Theme" id="theme-toggle-icon" width="24" height="24">
    </a>
  </div>
</div>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
<script src="/js/script.js"></script>
</body>
</html>