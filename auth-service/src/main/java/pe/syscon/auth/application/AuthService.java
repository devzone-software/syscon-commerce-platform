package pe.syscon.auth.application;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.SecureRandom;
import java.time.Instant;
import java.time.temporal.ChronoUnit;
import java.util.*;
import javax.crypto.spec.SecretKeySpec;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.http.HttpStatus;
import org.springframework.security.crypto.bcrypt.BCryptPasswordEncoder;
import org.springframework.security.oauth2.jose.jws.MacAlgorithm;
import org.springframework.security.oauth2.jwt.*;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;
import pe.syscon.auth.domain.*;
import pe.syscon.auth.infrastructure.*;
import pe.syscon.common.ApiException;

@Service
public class AuthService {
    private final UserRepository users; private final RoleRepository roles; private final RefreshTokenRepository refreshTokens;
    private final BCryptPasswordEncoder passwords = new BCryptPasswordEncoder();
    private final JwtEncoder jwtEncoder;
    private final SecureRandom random = new SecureRandom();
    public AuthService(UserRepository users,RoleRepository roles,RefreshTokenRepository refreshTokens,@Value("${security.jwt.secret}") String secret) {
        this.users=users;this.roles=roles;this.refreshTokens=refreshTokens;
        this.jwtEncoder=new NimbusJwtEncoder(new com.nimbusds.jose.jwk.source.ImmutableSecret<>(secret.getBytes(StandardCharsets.UTF_8)));
    }
    @Transactional
    public UUID register(String email,String password) {
        String normalized=email.strip().toLowerCase(Locale.ROOT);
        if(users.findByEmail(normalized).isPresent()) throw new ApiException(HttpStatus.CONFLICT,"Correo ya registrado");
        Role customer=roles.findById("CUSTOMER").orElseGet(() -> roles.save(new Role("CUSTOMER")));
        return users.save(new User(normalized,passwords.encode(password),customer)).id;
    }
    @Transactional
    public Tokens login(String email,String password) {
        User user=users.findByEmail(email.strip().toLowerCase(Locale.ROOT)).orElseThrow(() -> new ApiException(HttpStatus.UNAUTHORIZED,"Credenciales inválidas"));
        if(!passwords.matches(password,user.passwordHash)) throw new ApiException(HttpStatus.UNAUTHORIZED,"Credenciales inválidas");
        return issue(user);
    }
    @Transactional
    public Tokens refresh(String token) {
        RefreshToken old=refreshTokens.findByTokenHash(hash(token)).orElseThrow(() -> new ApiException(HttpStatus.UNAUTHORIZED,"Refresh token inválido"));
        if(old.revoked || old.expiresAt.isBefore(Instant.now())) throw new ApiException(HttpStatus.UNAUTHORIZED,"Refresh token expirado o revocado");
        old.revoked=true; refreshTokens.save(old);
        return issue(old.user);
    }
    @Transactional
    public void logout(String token) { refreshTokens.findByTokenHash(hash(token)).ifPresent(t -> {t.revoked=true;refreshTokens.save(t);}); }
    private Tokens issue(User user) {
        var authorities=user.roles.stream().flatMap(r -> java.util.stream.Stream.concat(java.util.stream.Stream.of("ROLE_"+r.name),r.permissions.stream().map(p -> p.name))).distinct().toList();
        Instant now=Instant.now();
        var claims=JwtClaimsSet.builder().issuer("syscon-auth").subject(user.id.toString()).issuedAt(now).expiresAt(now.plus(15,ChronoUnit.MINUTES)).claim("authorities",authorities).build();
        String access=jwtEncoder.encode(JwtEncoderParameters.from(JwsHeader.with(MacAlgorithm.HS256).build(),claims)).getTokenValue();
        byte[] bytes=new byte[48];random.nextBytes(bytes);String refresh=Base64.getUrlEncoder().withoutPadding().encodeToString(bytes);
        refreshTokens.save(new RefreshToken(hash(refresh),user,now.plus(30,ChronoUnit.DAYS)));
        return new Tokens(access,refresh,900);
    }
    private String hash(String token) {
        try { return Base64.getEncoder().encodeToString(MessageDigest.getInstance("SHA-256").digest(token.getBytes(StandardCharsets.UTF_8))); }
        catch(Exception e) { throw new IllegalStateException(e); }
    }
    public record Tokens(String accessToken,String refreshToken,long expiresInSeconds) {}
}
