$(document).ready(function () {
  function setCookie(name, value, days) {
    var expires = "";
    if (days) {
      var date = new Date();
      date.setTime(date.getTime() + (days*24*60*60*1000));
      expires = "; expires=" + date.toUTCString();
    }
    document.cookie = name + "=" + (value || "")  + expires + "; path=/";
  }

  function getCookie(name) {
    var nameEQ = name + "=";
    var ca = document.cookie.split(';');
    for(var i=0;i < ca.length;i++) {
        var c = ca[i];
        while (c.charAt(0)==' ') c = c.substring(1,c.length);
        if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length,c.length);
    }
    return null;
  }

  // Apply the theme based on the cookie or system preference
  function applyTheme() {
    var preferredTheme = getCookie('theme');
    if (!preferredTheme) {
      // If no cookie, fall back to system preference
      preferredTheme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    $('body').attr('data-theme', preferredTheme);
    $('#theme-toggle-icon').attr('src', preferredTheme === 'dark' ? '/images/light.svg' : '/images/dark.svg');
  }

  applyTheme();

  $('#theme-toggle').on('click', function(event) {
    event.preventDefault();
    var currentTheme = $('body').attr('data-theme') === 'dark' ? 'light' : 'dark';
    setCookie('theme', currentTheme, 7); // Save theme preference for 7 days
    applyTheme();
  });
  
  // Function to show a tooltip
  function showTooltip(message) {
    var tooltip = $('<span class="input-tooltip">' + message + '</span>');
    $('.input-group').append(tooltip);
    tooltip.fadeIn(300).delay(2000).fadeOut(500, function() { 
      $(this).remove(); 
    });
  }

  $('#steamID').focus();

  $('#lookupButton').on('click', function(e) {
    e.preventDefault(); // Prevent form submission if using <form>
    var steamID = $('#steamID').val().trim();
    if (!steamID) {
      // If input is empty, show tooltip and return
      showTooltip("Please enter a SteamID, URL, or Username");
      return;
    }
    performLookup(steamID);
  });

  $('#steamID').on('keypress', function(e) {
    if (e.which == 13) {
      e.preventDefault();
      var steamID = $(this).val().trim();
      if (!steamID) {
        // If input is empty, show tooltip and return
        showTooltip("Please enter a SteamID, URL, or Username");
        return;
      }
      performLookup(steamID);
    }
  });

  function performLookup(steamID) {
      var steamID = $('#steamID').val();
      $.ajax({
          url: '/api/lookup.php?steamid=' + steamID,
          type: 'POST',
          dataType: 'json',
          data: {steamid: steamID},
          success: function(response) {
              $('#results').html(response.html).show();

              var interpretedText;
              var linkHref = '/lookup/';

              if (response.interpretedType === 'customURL') {
                  interpretedText = 'Input ' + response.steamProfileName + ' interpreted as customURL';
                  linkHref += response.steamProfileName;
              } else {
                  interpretedText = 'Input ' + response.steamIDValue + ' interpreted as ' +  response.interpretedType;
                  linkHref += response.steamIDValue;
              }

              var interpretedDivContent = `<span>${interpretedText}</span>
                                            <a href="${linkHref}" target="_blank">
                                            <img src="/images/link.svg" alt="Link to this page" title="Link to this page" width="18" height="18" class="icon-link" />
                                            </a>`;

              $('#inputInterpreted').html(interpretedDivContent).show();

              if (response.cacheInfo) {
                $('#cacheInfo').html(`<i>${response.cacheInfo}</i>`).show();
              } else {
                $('#cacheInfo').hide();
              }
          },
          error: function() {
            $('#inputInterpreted').empty().hide();
            $('#results').html('<p>Error retrieving data. Please try again.</p>').show();
        }
    });
}

$(document).on('click', '.icon-copy', function() {
  var textToCopy = $(this).attr('data-copy-value');
  var $message = $(this).siblings('.message-copied');
  copyToClipboard(textToCopy, $message);
});

function copyToClipboard(textToCopy, $message) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(textToCopy).then(function() {
            showCopiedMessage($message);
        }, function(err) {
            console.error('Could not copy text: ', err);
        });
    } else {
        var textArea = document.createElement("textarea");
        textArea.value = textToCopy;
        document.body.appendChild(textArea);
        textArea.select();
        try {
            var successful = document.execCommand('copy');
            if (successful) {
                showCopiedMessage($message);
            } else {
                console.error('Fallback: Could not copy text');
            }
        } finally {
            document.body.removeChild(textArea);
        }
    }
}

function showCopiedMessage($element) {
  $element.css({'visibility': 'visible', 'opacity': 1}).fadeIn(100).delay(200).fadeOut(700, function() {
      $(this).css({'visibility': 'hidden', 'opacity': 0});
  });
}

});