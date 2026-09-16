package pe.syscon.auth.infrastructure;
import java.util.*;
import org.springframework.data.jpa.repository.JpaRepository;
import pe.syscon.auth.domain.RefreshToken;
public interface RefreshTokenRepository extends JpaRepository<RefreshToken,UUID> { Optional<RefreshToken> findByTokenHash(String hash); }
