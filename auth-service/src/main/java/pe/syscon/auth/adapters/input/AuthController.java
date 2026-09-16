package pe.syscon.auth.adapters.input;

import java.util.UUID;
import jakarta.validation.Valid;
import jakarta.validation.constraints.*;
import org.springframework.http.HttpStatus;
import org.springframework.security.core.Authentication;
import org.springframework.web.bind.annotation.*;
import pe.syscon.auth.application.AuthService;
import pe.syscon.common.ApiResponse;

@RestController @RequestMapping("/api/auth")
public class AuthController {
    private final AuthService service;
    public AuthController(AuthService service) { this.service=service; }
    public record Register(@Email @NotBlank String email,@NotBlank @Size(min=12) String password) {}
    public record Login(@Email @NotBlank String email,@NotBlank String password) {}
    public record TokenRequest(@NotBlank String refreshToken) {}
    @PostMapping("/register") @ResponseStatus(HttpStatus.CREATED)
    public ApiResponse<UUID> register(@Valid @RequestBody Register body) { return ApiResponse.ok("Usuario creado",service.register(body.email(),body.password())); }
    @PostMapping("/login") public ApiResponse<AuthService.Tokens> login(@Valid @RequestBody Login body) { return ApiResponse.ok("Autenticado",service.login(body.email(),body.password())); }
    @PostMapping("/refresh") public ApiResponse<AuthService.Tokens> refresh(@Valid @RequestBody TokenRequest body) { return ApiResponse.ok("Token renovado",service.refresh(body.refreshToken())); }
    @PostMapping("/logout") public ApiResponse<Void> logout(@Valid @RequestBody TokenRequest body) { service.logout(body.refreshToken());return ApiResponse.ok("Sesión cerrada",null); }
    @GetMapping("/me") public ApiResponse<String> me(Authentication authentication) { return ApiResponse.ok("Usuario",authentication.getName()); }
}
