package pe.syscon.common;

import javax.crypto.spec.SecretKeySpec;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.security.config.Customizer;
import org.springframework.security.config.annotation.method.configuration.EnableMethodSecurity;
import org.springframework.security.config.annotation.web.builders.HttpSecurity;
import org.springframework.security.core.authority.SimpleGrantedAuthority;
import org.springframework.security.oauth2.jwt.JwtDecoder;
import org.springframework.security.oauth2.jwt.NimbusJwtDecoder;
import org.springframework.security.oauth2.server.resource.authentication.JwtAuthenticationConverter;
import org.springframework.security.web.SecurityFilterChain;

@Configuration
@EnableMethodSecurity
public class SecurityConfiguration {
    @Bean
    SecurityFilterChain securityFilterChain(HttpSecurity http) throws Exception {
        return http.csrf(csrf -> csrf.disable()).authorizeHttpRequests(a -> a
            .requestMatchers("/actuator/health", "/v3/api-docs/**", "/swagger-ui/**", "/swagger-ui.html", "/api/auth/register", "/api/auth/login", "/api/auth/refresh", "/api/catalog/products", "/api/catalog/products/*", "/api/catalog/categories", "/api/catalog/brands", "/api/catalog/internal/**", "/api/orders/internal/**", "/api/payments/internal/**", "/api/invoices/internal/**").permitAll()
            .anyRequest().authenticated()).oauth2ResourceServer(o -> o.jwt(jwt -> jwt.jwtAuthenticationConverter(jwtAuthorities()))).build();
    }
    @Bean
    JwtDecoder jwtDecoder(@Value("${security.jwt.secret}") String secret) {
        if (secret.getBytes(java.nio.charset.StandardCharsets.UTF_8).length < 32 || secret.startsWith("replace-with")) throw new IllegalStateException("JWT secret must be a non-placeholder value of at least 32 bytes");
        return NimbusJwtDecoder.withSecretKey(new SecretKeySpec(secret.getBytes(java.nio.charset.StandardCharsets.UTF_8), "HmacSHA256")).build();
    }
    private JwtAuthenticationConverter jwtAuthorities() {
        var c = new JwtAuthenticationConverter();
        c.setJwtGrantedAuthoritiesConverter(jwt -> {
            var claims = jwt.getClaimAsStringList("authorities");
            return claims == null ? java.util.List.<org.springframework.security.core.GrantedAuthority>of() : claims.stream().<org.springframework.security.core.GrantedAuthority>map(SimpleGrantedAuthority::new).toList();
        });
        return c;
    }
}
